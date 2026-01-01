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

    public function __construct()
    {
        global $db, $config;

        require_once __DIR__ . '/../../Services/PushService.php';

        $this->db = $db;
        $this->config = $config;
        $this->pushService = new PushService($config['push'] ?? [], $db);
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
        // Get current user
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Check if push is enabled
        if (!$this->pushService->isEnabled()) {
            jsonResponse(['error' => 'Push notifications are not enabled'], 503);
            return;
        }

        // Parse request body
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['subscription'])) {
            jsonResponse(['error' => 'Invalid request body'], 400);
            return;
        }

        $subscription = $input['subscription'];

        // Validate subscription data
        if (empty($subscription['endpoint'])) {
            jsonResponse(['error' => 'Missing endpoint'], 400);
            return;
        }

        if (empty($subscription['keys']['p256dh']) || empty($subscription['keys']['auth'])) {
            jsonResponse(['error' => 'Missing subscription keys'], 400);
            return;
        }

        // Store the subscription
        $success = $this->pushService->subscribe((int) $user['id'], $subscription);

        if ($success) {
            jsonResponse([
                'success' => true,
                'message' => 'Subscription saved successfully'
            ]);
        } else {
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
     * Get VAPID public key for client-side subscription
     * GET /api/push/key
     */
    public function key(): void
    {
        if (!$this->pushService->isEnabled()) {
            jsonResponse(['error' => 'Push notifications are not enabled'], 503);
            return;
        }

        jsonResponse([
            'publicKey' => $this->pushService->getPublicKey()
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
