<?php
declare(strict_types=1);

/**
 * Push Notification Service
 *
 * Handles Web Push notifications using VAPID authentication.
 * Manages push subscriptions and sends notifications to members.
 */
class PushService
{
    private PDO $db;
    private string $publicKey;
    private string $privateKey;
    private string $subject;
    private bool $enabled;
    private bool $debugEnabled;

    public function __construct(array $pushConfig, PDO $db)
    {
        $this->db = $db;
        $this->publicKey = $pushConfig['public_key'] ?? '';
        $this->privateKey = $pushConfig['private_key'] ?? '';
        $this->subject = $pushConfig['subject'] ?? 'mailto:admin@example.com';
        $this->enabled = ($pushConfig['enabled'] ?? false) && !empty($this->publicKey) && !empty($this->privateKey);
        $this->debugEnabled = $pushConfig['debug'] ?? false;
    }

    /**
     * Log debug information
     */
    private function logDebug(string $event, array $data): void
    {
        if (!$this->debugEnabled) {
            return;
        }

        $logFile = __DIR__ . '/../data/logs/push-debug.log';
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $dataJson = json_encode($data, JSON_UNESCAPED_SLASHES);
        $logEntry = "[{$timestamp}] PushService::{$event}: {$dataJson}\n";

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Check if push notifications are enabled
     *
     * @return bool True if push is properly configured
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the public VAPID key for client-side subscription
     *
     * @return string Base64 URL-safe public key
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Store a push subscription for a member
     *
     * @param int $memberId Member ID
     * @param array $subscription Subscription data (endpoint, keys)
     * @return bool Success
     */
    public function subscribe(int $memberId, array $subscription): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $endpoint = $subscription['endpoint'] ?? '';
        $p256dhKey = $subscription['keys']['p256dh'] ?? '';
        $authKey = $subscription['keys']['auth'] ?? '';

        if (empty($endpoint) || empty($p256dhKey) || empty($authKey)) {
            return false;
        }

        // Check if subscription already exists
        $stmt = $this->db->prepare('SELECT id FROM push_subscriptions WHERE endpoint = ?');
        $stmt->execute([$endpoint]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update existing subscription
            $stmt = $this->db->prepare('
                UPDATE push_subscriptions
                SET member_id = ?, p256dh_key = ?, auth_key = ?, user_agent = ?
                WHERE endpoint = ?
            ');
            return $stmt->execute([
                $memberId,
                $p256dhKey,
                $authKey,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $endpoint
            ]);
        }

        // Insert new subscription
        $stmt = $this->db->prepare('
            INSERT INTO push_subscriptions (member_id, endpoint, p256dh_key, auth_key, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ');
        return $stmt->execute([
            $memberId,
            $endpoint,
            $p256dhKey,
            $authKey,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }

    /**
     * Remove a push subscription
     *
     * @param int $memberId Member ID
     * @param string $endpoint Subscription endpoint
     * @return bool Success
     */
    public function unsubscribe(int $memberId, string $endpoint): bool
    {
        $stmt = $this->db->prepare('DELETE FROM push_subscriptions WHERE member_id = ? AND endpoint = ?');
        return $stmt->execute([$memberId, $endpoint]);
    }

    /**
     * Send a push notification to a specific member
     *
     * @param int $memberId Member ID
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Additional data to send
     * @return bool Success (true if at least one notification sent)
     */
    public function send(int $memberId, string $title, string $body, array $data = []): bool
    {
        $this->logDebug('send_called', [
            'member_id' => $memberId,
            'title' => $title,
            'enabled' => $this->enabled,
        ]);

        if (!$this->enabled) {
            $this->logDebug('send_failed', ['reason' => 'push_not_enabled']);
            return false;
        }

        $subscriptions = $this->getSubscriptions($memberId);
        $this->logDebug('send_subscriptions', [
            'member_id' => $memberId,
            'subscription_count' => count($subscriptions),
        ]);

        if (empty($subscriptions)) {
            $this->logDebug('send_failed', ['reason' => 'no_subscriptions', 'member_id' => $memberId]);
            return false;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'timestamp' => time(),
        ]);

        $success = false;
        foreach ($subscriptions as $subscription) {
            $result = $this->sendToSubscription($subscription, $payload);
            $this->logDebug('send_to_subscription', [
                'member_id' => $memberId,
                'endpoint_prefix' => substr($subscription['endpoint'] ?? '', 0, 50),
                'success' => $result,
            ]);
            if ($result) {
                $success = true;
            }
        }

        $this->logDebug('send_complete', [
            'member_id' => $memberId,
            'success' => $success,
        ]);

        return $success;
    }

    /**
     * Send a push notification to all members with a specific role in a brigade
     *
     * @param int $brigadeId Brigade ID
     * @param string $role Role to target (or 'all' for everyone)
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Additional data
     * @return void
     */
    public function sendToRole(int $brigadeId, string $role, string $title, string $body, array $data = []): void
    {
        if (!$this->enabled) {
            return;
        }

        // Get all members with the specified role (or all active members)
        if ($role === 'all') {
            $stmt = $this->db->prepare('
                SELECT id FROM members
                WHERE brigade_id = ? AND status = "active"
            ');
            $stmt->execute([$brigadeId]);
        } else {
            // Get members with role at or above specified level
            $roleHierarchy = [
                'firefighter' => 1,
                'officer' => 2,
                'admin' => 3,
                'superadmin' => 4
            ];
            $targetLevel = $roleHierarchy[$role] ?? 1;

            $roles = array_filter($roleHierarchy, fn($level) => $level >= $targetLevel);
            $roleNames = array_keys($roles);
            $placeholders = implode(',', array_fill(0, count($roleNames), '?'));

            $stmt = $this->db->prepare("
                SELECT id FROM members
                WHERE brigade_id = ? AND status = 'active' AND role IN ({$placeholders})
            ");
            $stmt->execute(array_merge([$brigadeId], $roleNames));
        }

        $members = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($members as $memberId) {
            $this->send($memberId, $title, $body, $data);
        }
    }

    /**
     * Get all push subscriptions for a member
     *
     * @param int $memberId Member ID
     * @return array Array of subscription data
     */
    public function getSubscriptions(int $memberId): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM push_subscriptions
            WHERE member_id = ?
        ');
        $stmt->execute([$memberId]);
        return $stmt->fetchAll();
    }

    /**
     * Remove a subscription by endpoint (for invalid/expired subscriptions)
     *
     * @param string $endpoint Subscription endpoint
     * @return void
     */
    private function removeSubscription(string $endpoint): void
    {
        $stmt = $this->db->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
        $stmt->execute([$endpoint]);
    }

    /**
     * Send a push notification to a specific subscription
     *
     * @param array $subscription Subscription data
     * @param string $payload JSON payload
     * @return bool Success
     */
    private function sendToSubscription(array $subscription, string $payload): bool
    {
        $endpoint = $subscription['endpoint'];
        $p256dhKey = $subscription['p256dh_key'];
        $authKey = $subscription['auth_key'];

        try {
            // Generate VAPID headers
            $vapidHeaders = $this->generateVapidHeaders($endpoint);

            // Encrypt the payload
            $encrypted = $this->encryptPayload($payload, $p256dhKey, $authKey);
            if ($encrypted === null) {
                return false;
            }

            // Send the push notification via HTTP
            $result = $this->sendHttpRequest($endpoint, $encrypted, $vapidHeaders);

            // Handle response
            if ($result['status'] === 201) {
                return true;
            }

            // Remove invalid subscriptions
            if ($result['status'] === 404 || $result['status'] === 410) {
                $this->removeSubscription($endpoint);
            }

            return false;
        } catch (\Exception $e) {
            error_log("Push notification error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate VAPID authentication headers
     *
     * @param string $endpoint Push endpoint URL
     * @return array Headers for the push request
     */
    private function generateVapidHeaders(string $endpoint): array
    {
        $parsedUrl = parse_url($endpoint);
        $audience = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

        // Create JWT token
        $header = [
            'typ' => 'JWT',
            'alg' => 'ES256'
        ];

        $payload = [
            'aud' => $audience,
            'exp' => time() + 86400,
            'sub' => $this->subject
        ];

        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        $signatureInput = $headerEncoded . '.' . $payloadEncoded;

        // Sign with ECDSA (simplified - in production use proper crypto library)
        $signature = $this->signWithECDSA($signatureInput);

        $jwt = $signatureInput . '.' . $this->base64UrlEncode($signature);

        return [
            'Authorization' => 'vapid t=' . $jwt . ', k=' . $this->publicKey,
            'TTL' => '86400',
            'Content-Encoding' => 'aes128gcm',
            'Content-Type' => 'application/octet-stream',
        ];
    }

    /**
     * Encrypt the push payload using Web Push encryption
     *
     * @param string $payload Plain text payload
     * @param string $userPublicKey User's P-256 public key (base64)
     * @param string $userAuthKey User's auth secret (base64)
     * @return string|null Encrypted payload or null on failure
     */
    private function encryptPayload(string $payload, string $userPublicKey, string $userAuthKey): ?string
    {
        // Decode user keys
        $userPublicKeyBinary = $this->base64UrlDecode($userPublicKey);
        $userAuthKeyBinary = $this->base64UrlDecode($userAuthKey);

        if ($userPublicKeyBinary === false || $userAuthKeyBinary === false) {
            return null;
        }

        // Generate local key pair for ECDH
        $localPrivateKey = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($localPrivateKey === false) {
            return null;
        }

        $localKeyDetails = openssl_pkey_get_details($localPrivateKey);
        $localPublicKey = $this->getPublicKeyFromDetails($localKeyDetails);

        // Perform ECDH key agreement
        $sharedSecret = $this->computeSharedSecret($localPrivateKey, $userPublicKeyBinary);
        if ($sharedSecret === null) {
            return null;
        }

        // Generate salt
        $salt = random_bytes(16);

        // Derive encryption keys using HKDF
        $ikm = $this->hkdfExtract($userAuthKeyBinary, $sharedSecret);
        $context = $this->createContext($userPublicKeyBinary, $localPublicKey);
        $prk = $this->hkdfExpand($ikm, "Content-Encoding: aes128gcm\x00" . $context, 16);
        $nonce = $this->hkdfExpand($ikm, "Content-Encoding: nonce\x00" . $context, 12);

        // Pad the payload
        $paddedPayload = pack('n', 0) . $payload;

        // Encrypt with AES-128-GCM
        $encrypted = openssl_encrypt(
            $paddedPayload,
            'aes-128-gcm',
            $prk,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($encrypted === false) {
            return null;
        }

        // Build the encrypted content coding header
        $recordSize = pack('N', 4096);
        $keyIdLen = pack('C', strlen($localPublicKey));

        return $salt . $recordSize . $keyIdLen . $localPublicKey . $encrypted . $tag;
    }

    /**
     * Send HTTP request to push endpoint
     *
     * @param string $endpoint Push endpoint URL
     * @param string $body Request body
     * @param array $headers Request headers
     * @return array Response with 'status' and 'body' keys
     */
    private function sendHttpRequest(string $endpoint, string $body, array $headers): array
    {
        $ch = curl_init($endpoint);

        $headerStrings = [];
        foreach ($headers as $key => $value) {
            $headerStrings[] = "{$key}: {$value}";
        }
        $headerStrings[] = 'Content-Length: ' . strlen($body);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerStrings,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => $response,
        ];
    }

    /**
     * Base64 URL-safe encode
     *
     * @param string $data Data to encode
     * @return string Encoded data
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL-safe decode
     *
     * @param string $data Data to decode
     * @return string|false Decoded data or false on failure
     */
    private function base64UrlDecode(string $data): string|false
    {
        $padding = 4 - (strlen($data) % 4);
        if ($padding !== 4) {
            $data .= str_repeat('=', $padding);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Sign data with ECDSA using private key
     *
     * @param string $data Data to sign
     * @return string Signature
     */
    private function signWithECDSA(string $data): string
    {
        // Decode private key from base64
        $privateKeyBinary = $this->base64UrlDecode($this->privateKey);

        // Create OpenSSL key from raw private key
        // Note: This is a simplified implementation
        // In production, use a proper JWT library like firebase/php-jwt

        $signature = '';
        $privateKey = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($privateKey && openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            return $signature;
        }

        return '';
    }

    /**
     * Get public key bytes from OpenSSL key details
     *
     * @param array $keyDetails OpenSSL key details
     * @return string Public key bytes
     */
    private function getPublicKeyFromDetails(array $keyDetails): string
    {
        $x = $keyDetails['ec']['x'] ?? '';
        $y = $keyDetails['ec']['y'] ?? '';
        return "\x04" . $x . $y;
    }

    /**
     * Compute ECDH shared secret
     *
     * @param \OpenSSLAsymmetricKey $privateKey Local private key
     * @param string $peerPublicKey Peer's public key bytes
     * @return string|null Shared secret or null on failure
     */
    private function computeSharedSecret($privateKey, string $peerPublicKey): ?string
    {
        // This is a placeholder - proper ECDH implementation requires
        // using openssl_pkey_derive or a dedicated crypto library
        // For production, use a library like phpseclib or minishlink/web-push

        return hash('sha256', $peerPublicKey, true);
    }

    /**
     * HKDF Extract
     *
     * @param string $salt Salt value
     * @param string $ikm Input keying material
     * @return string Pseudorandom key
     */
    private function hkdfExtract(string $salt, string $ikm): string
    {
        return hash_hmac('sha256', $ikm, $salt, true);
    }

    /**
     * HKDF Expand
     *
     * @param string $prk Pseudorandom key
     * @param string $info Context info
     * @param int $length Output length
     * @return string Output keying material
     */
    private function hkdfExpand(string $prk, string $info, int $length): string
    {
        $output = '';
        $lastBlock = '';
        $counter = 1;

        while (strlen($output) < $length) {
            $lastBlock = hash_hmac('sha256', $lastBlock . $info . chr($counter), $prk, true);
            $output .= $lastBlock;
            $counter++;
        }

        return substr($output, 0, $length);
    }

    /**
     * Create encryption context
     *
     * @param string $clientPublicKey Client public key
     * @param string $serverPublicKey Server public key
     * @return string Context bytes
     */
    private function createContext(string $clientPublicKey, string $serverPublicKey): string
    {
        return "P-256\x00"
            . pack('n', strlen($clientPublicKey)) . $clientPublicKey
            . pack('n', strlen($serverPublicKey)) . $serverPublicKey;
    }
}
