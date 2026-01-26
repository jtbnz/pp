<?php
declare(strict_types=1);

namespace Portal\Services;

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use PDO;

/**
 * Push Notification Service
 *
 * Handles Web Push notifications using VAPID authentication.
 * Uses minishlink/web-push library for proper encryption and signing.
 */
class PushService
{
    private PDO $db;
    private string $publicKey;
    private string $privateKey;
    private string $subject;
    private bool $enabled;
    private bool $debugEnabled;
    private ?WebPush $webPush = null;

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
     * Get or create the WebPush instance
     */
    private function getWebPush(): ?WebPush
    {
        if ($this->webPush !== null) {
            return $this->webPush;
        }

        if (!$this->enabled) {
            return null;
        }

        try {
            $auth = [
                'VAPID' => [
                    'subject' => $this->subject,
                    'publicKey' => $this->publicKey,
                    'privateKey' => $this->privateKey,
                ],
            ];

            $this->webPush = new WebPush($auth);
            $this->webPush->setReuseVAPIDHeaders(true);

            return $this->webPush;
        } catch (\Exception $e) {
            $this->logDebug('webpush_init_error', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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

        $webPush = $this->getWebPush();
        if ($webPush === null) {
            $this->logDebug('send_failed', ['reason' => 'webpush_not_initialized']);
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

        // Queue all notifications
        foreach ($subscriptions as $sub) {
            try {
                $subscription = Subscription::create([
                    'endpoint' => $sub['endpoint'],
                    'publicKey' => $sub['p256dh_key'],
                    'authToken' => $sub['auth_key'],
                ]);

                $webPush->queueNotification($subscription, $payload);

                $this->logDebug('notification_queued', [
                    'member_id' => $memberId,
                    'endpoint_prefix' => substr($sub['endpoint'], 0, 50),
                ]);
            } catch (\Exception $e) {
                $this->logDebug('queue_error', [
                    'member_id' => $memberId,
                    'endpoint_prefix' => substr($sub['endpoint'] ?? '', 0, 50),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Send all queued notifications
        $success = false;
        $expiredEndpoints = [];

        try {
            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getRequest()->getUri()->__toString();
                $endpointPrefix = substr($endpoint, 0, 50);

                if ($report->isSuccess()) {
                    $success = true;
                    $this->logDebug('send_success', [
                        'member_id' => $memberId,
                        'endpoint_prefix' => $endpointPrefix,
                    ]);
                } else {
                    $reason = $report->getReason();
                    $statusCode = $report->getResponse() ? $report->getResponse()->getStatusCode() : 0;

                    $this->logDebug('send_failed_endpoint', [
                        'member_id' => $memberId,
                        'endpoint_prefix' => $endpointPrefix,
                        'reason' => $reason,
                        'status_code' => $statusCode,
                    ]);

                    // Mark expired/invalid subscriptions for removal
                    if ($report->isSubscriptionExpired()) {
                        $expiredEndpoints[] = $endpoint;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logDebug('flush_error', [
                'member_id' => $memberId,
                'error' => $e->getMessage(),
            ]);
        }

        // Remove expired subscriptions
        foreach ($expiredEndpoints as $endpoint) {
            $this->removeSubscription($endpoint);
            $this->logDebug('subscription_removed', [
                'member_id' => $memberId,
                'endpoint_prefix' => substr($endpoint, 0, 50),
                'reason' => 'expired',
            ]);
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
}
