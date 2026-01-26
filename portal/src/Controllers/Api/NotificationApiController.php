<?php
declare(strict_types=1);

namespace Portal\Controllers\Api;

use Portal\Services\NotificationService;
use Portal\Services\NotificationPreferencesService;
use PDO;

/**
 * Notification API Controller
 *
 * Handles notification management endpoints for the notification center.
 */
class NotificationApiController
{
    private PDO $db;
    private array $config;
    private NotificationService $notificationService;
    private NotificationPreferencesService $preferencesService;

    public function __construct()
    {
        global $db, $config;

        $this->db = $db;
        $this->config = $config;
        $basePath = $config['base_path'] ?? '';
        $this->notificationService = new NotificationService($db, $basePath);
        $this->preferencesService = new NotificationPreferencesService($db);
    }

    /**
     * Get notifications for current user
     * GET /api/notifications
     *
     * Query params:
     * - limit: int (default 50, max 100)
     * - offset: int (default 0)
     */
    public function index(): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $limit = min((int) ($_GET['limit'] ?? 50), 100);
        $offset = (int) ($_GET['offset'] ?? 0);

        $notifications = $this->notificationService->getForMember($user['id'], $limit, $offset);
        $total = $this->notificationService->getCountForMember($user['id']);
        $unreadCount = $this->notificationService->getUnreadCount($user['id']);

        jsonResponse([
            'notifications' => $notifications,
            'total' => $total,
            'unread_count' => $unreadCount,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total,
        ]);
    }

    /**
     * Get unread count for badge
     * GET /api/notifications/unread-count
     */
    public function unreadCount(): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $count = $this->notificationService->getUnreadCount($user['id']);

        jsonResponse(['count' => $count]);
    }

    /**
     * Mark a notification as read
     * PATCH /api/notifications/{id}/read
     */
    public function markRead(int $id): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $success = $this->notificationService->markAsRead($id, $user['id']);

        if (!$success) {
            // Either not found or already read
            jsonResponse(['error' => 'Notification not found or already read'], 404);
            return;
        }

        $unreadCount = $this->notificationService->getUnreadCount($user['id']);

        jsonResponse([
            'success' => true,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Mark all notifications as read
     * POST /api/notifications/mark-all-read
     */
    public function markAllRead(): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $count = $this->notificationService->markAllAsRead($user['id']);

        jsonResponse([
            'success' => true,
            'marked_count' => $count,
            'unread_count' => 0,
        ]);
    }

    /**
     * Delete a single notification
     * DELETE /api/notifications/{id}
     */
    public function delete(int $id): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $success = $this->notificationService->delete($id, $user['id']);

        if (!$success) {
            jsonResponse(['error' => 'Notification not found'], 404);
            return;
        }

        $unreadCount = $this->notificationService->getUnreadCount($user['id']);

        jsonResponse([
            'success' => true,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Clear all notifications
     * DELETE /api/notifications/clear
     */
    public function clear(): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $count = $this->notificationService->clearAll($user['id']);

        jsonResponse([
            'success' => true,
            'cleared_count' => $count,
            'unread_count' => 0,
        ]);
    }

    /**
     * Get notification preferences
     * GET /api/notifications/preferences
     */
    public function getPreferences(): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $preferences = $this->preferencesService->get($user['id']);

        jsonResponse([
            'preferences' => $preferences,
            'types' => [
                'system_alerts' => [
                    'label' => 'System Alerts',
                    'description' => 'Urgent system notifications and alerts',
                    'color' => NotificationService::TYPE_COLORS[NotificationService::TYPE_SYSTEM_ALERT],
                ],
                'messages' => [
                    'label' => 'Messages',
                    'description' => 'General messages and communications',
                    'color' => NotificationService::TYPE_COLORS[NotificationService::TYPE_MESSAGE],
                ],
                'updates' => [
                    'label' => 'Updates',
                    'description' => 'Status updates and changes',
                    'color' => NotificationService::TYPE_COLORS[NotificationService::TYPE_UPDATE],
                ],
                'reminders' => [
                    'label' => 'Reminders',
                    'description' => 'Training and event reminders',
                    'color' => NotificationService::TYPE_COLORS[NotificationService::TYPE_REMINDER],
                ],
            ],
        ]);
    }

    /**
     * Update notification preferences
     * PUT /api/notifications/preferences
     *
     * Request body:
     * {
     *   "system_alerts": true,
     *   "messages": true,
     *   "updates": false,
     *   "reminders": true
     * }
     */
    public function updatePreferences(): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            jsonResponse(['error' => 'Invalid request body'], 400);
            return;
        }

        $this->preferencesService->update($user['id'], $input);
        $preferences = $this->preferencesService->get($user['id']);

        jsonResponse([
            'success' => true,
            'preferences' => $preferences,
        ]);
    }
}
