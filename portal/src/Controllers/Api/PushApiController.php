<?php
declare(strict_types=1);

/**
 * Push API Controller
 *
 * Handles push subscription management endpoints.
 */
class PushApiController
{
    private PDO $db;
    private array $config;
    private PushService $pushService;
    private bool $debugEnabled;

    public function __construct()
    {
        global $db, $config;

        require_once __DIR__ . '/../../Services/PushService.php';

        $this->db = $db;
        $this->config = $config;
        $this->pushService = new PushService($config['push'] ?? [], $db);
        $this->debugEnabled = $config['push']['debug'] ?? false;
    }

    /**
     * Log push debug information
     */
    private function logPushDebug(string $event, array $data): void
    {
        if (!$this->debugEnabled) {
            return;
        }

        $logFile = __DIR__ . '/../../data/logs/push-debug.log';
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $dataJson = json_encode($data, JSON_UNESCAPED_SLASHES);
        $logEntry = "[{$timestamp}] {$event}: {$dataJson}\n";

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Subscribe to push notifications
     * POST /api/push/subscribe
     *
     * Request body:
     * {
     *   "subscription": {
     *     "endpoint": "https://...",
     *     "keys": {
     *       "p256dh": "...",
     *       "auth": "..."
     *     }
     *   }
     * }
     */
    public function subscribe(): void
    {
        $this->logPushDebug('subscribe_request', [
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ]);

        // Get current user
        $user = currentUser();
        if (!$user) {
            $this->logPushDebug('subscribe_failed', ['reason' => 'unauthorized']);
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Check if push is enabled
        if (!$this->pushService->isEnabled()) {
            $this->logPushDebug('subscribe_failed', ['reason' => 'push_not_enabled', 'member_id' => $user['id']]);
            jsonResponse(['error' => 'Push notifications are not enabled'], 503);
            return;
        }

        // Parse request body
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['subscription'])) {
            $this->logPushDebug('subscribe_failed', ['reason' => 'invalid_body', 'member_id' => $user['id']]);
            jsonResponse(['error' => 'Invalid request body'], 400);
            return;
        }

        $subscription = $input['subscription'];

        // Validate subscription data
        if (empty($subscription['endpoint'])) {
            $this->logPushDebug('subscribe_failed', ['reason' => 'missing_endpoint', 'member_id' => $user['id']]);
            jsonResponse(['error' => 'Missing endpoint'], 400);
            return;
        }

        if (empty($subscription['keys']['p256dh']) || empty($subscription['keys']['auth'])) {
            $this->logPushDebug('subscribe_failed', ['reason' => 'missing_keys', 'member_id' => $user['id']]);
            jsonResponse(['error' => 'Missing subscription keys'], 400);
            return;
        }

        $this->logPushDebug('subscribe_attempt', [
            'member_id' => $user['id'],
            'endpoint_prefix' => substr($subscription['endpoint'], 0, 50) . '...',
            'has_p256dh' => !empty($subscription['keys']['p256dh']),
            'has_auth' => !empty($subscription['keys']['auth']),
        ]);

        // Store the subscription
        $success = $this->pushService->subscribe((int) $user['id'], $subscription);

        if ($success) {
            $this->logPushDebug('subscribe_success', ['member_id' => $user['id']]);
            jsonResponse([
                'success' => true,
                'message' => 'Subscription saved successfully'
            ]);
        } else {
            $this->logPushDebug('subscribe_failed', ['reason' => 'db_error', 'member_id' => $user['id']]);
            jsonResponse(['error' => 'Failed to save subscription'], 500);
        }
    }

    /**
     * Unsubscribe from push notifications
     * POST /api/push/unsubscribe
     *
     * Request body:
     * {
     *   "endpoint": "https://..."
     * }
     */
    public function unsubscribe(): void
    {
        // Get current user
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Parse request body
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['endpoint'])) {
            jsonResponse(['error' => 'Missing endpoint'], 400);
            return;
        }

        $endpoint = $input['endpoint'];

        // Remove the subscription
        $success = $this->pushService->unsubscribe((int) $user['id'], $endpoint);

        if ($success) {
            jsonResponse([
                'success' => true,
                'message' => 'Unsubscribed successfully'
            ]);
        } else {
            jsonResponse(['error' => 'Failed to unsubscribe'], 500);
        }
    }

    /**
     * Send a test push notification (admin only)
     * POST /api/push/test
     */
    public function test(): void
    {
        // Get current user
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Require admin role
        if (!hasRole('admin')) {
            jsonResponse(['error' => 'Forbidden - admin access required'], 403);
            return;
        }

        // Check if push is enabled
        if (!$this->pushService->isEnabled()) {
            jsonResponse(['error' => 'Push notifications are not enabled'], 503);
            return;
        }

        // Send test notification to current user
        $success = $this->pushService->send(
            (int) $user['id'],
            'Test Notification',
            'This is a test notification from Puke Portal.',
            [
                'type' => 'test',
                'url' => $this->config['app_url'] ?? '/',
            ]
        );

        if ($success) {
            jsonResponse([
                'success' => true,
                'message' => 'Test notification sent'
            ]);
        } else {
            jsonResponse([
                'success' => false,
                'message' => 'No subscriptions found or notification failed to send'
            ], 400);
        }
    }

    /**
     * Debug endpoint to test if requests reach the server
     * GET /api/push/debug
     */
    public function debug(): void
    {
        // Always log, regardless of debug setting
        $logFile = __DIR__ . '/../../data/logs/push-debug.log';
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $data = [
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'debug_enabled' => $this->debugEnabled,
            'push_enabled' => $this->pushService->isEnabled(),
            'has_public_key' => !empty($this->config['push']['public_key'] ?? ''),
            'has_private_key' => !empty($this->config['push']['private_key'] ?? ''),
        ];
        $logEntry = "[{$timestamp}] DEBUG_ENDPOINT: " . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

        jsonResponse([
            'debug_enabled' => $this->debugEnabled,
            'push_enabled' => $this->pushService->isEnabled(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'timestamp' => $timestamp,
        ]);
    }

    /**
     * Get VAPID public key for client-side subscription
     * GET /api/push/key
     */
    public function key(): void
    {
        $this->logPushDebug('key_request', [
            'is_enabled' => $this->pushService->isEnabled(),
            'has_public_key' => !empty($this->config['push']['public_key'] ?? ''),
            'has_private_key' => !empty($this->config['push']['private_key'] ?? ''),
            'push_config_enabled' => $this->config['push']['enabled'] ?? false,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ]);

        if (!$this->pushService->isEnabled()) {
            $this->logPushDebug('key_request_failed', [
                'reason' => 'push_not_enabled',
            ]);
            jsonResponse(['error' => 'Push notifications are not enabled'], 503);
            return;
        }

        $publicKey = $this->pushService->getPublicKey();
        $this->logPushDebug('key_request_success', [
            'public_key_length' => strlen($publicKey),
            'public_key_prefix' => substr($publicKey, 0, 20) . '...',
        ]);

        jsonResponse([
            'publicKey' => $publicKey
        ]);
    }

    /**
     * Get subscription status for current user
     * GET /api/push/status
     */
    public function status(): void
    {
        // Get current user
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $subscriptions = $this->pushService->getSubscriptions((int) $user['id']);

        jsonResponse([
            'enabled' => $this->pushService->isEnabled(),
            'subscribed' => !empty($subscriptions),
            'subscriptionCount' => count($subscriptions)
        ]);
    }
}
