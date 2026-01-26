<?php
declare(strict_types=1);

namespace Portal\Services;

use PDO;

/**
 * Notification Service
 *
 * Manages in-app notifications stored in the database.
 * Works alongside PushService to provide a persistent notification history.
 */
class NotificationService
{
    private PDO $db;
    private string $basePath;

    // Notification types with their visual properties
    public const TYPE_SYSTEM_ALERT = 'system_alert';  // Red - urgent/system alerts
    public const TYPE_MESSAGE = 'message';            // Blue - general messages
    public const TYPE_UPDATE = 'update';              // Green - status updates
    public const TYPE_REMINDER = 'reminder';          // Yellow - training/event reminders

    public const TYPES = [
        self::TYPE_SYSTEM_ALERT,
        self::TYPE_MESSAGE,
        self::TYPE_UPDATE,
        self::TYPE_REMINDER,
    ];

    public const TYPE_COLORS = [
        self::TYPE_SYSTEM_ALERT => '#D32F2F',  // Red
        self::TYPE_MESSAGE => '#1976D2',       // Blue
        self::TYPE_UPDATE => '#388E3C',        // Green
        self::TYPE_REMINDER => '#FBC02D',      // Yellow
    ];

    public const TYPE_ICONS = [
        self::TYPE_SYSTEM_ALERT => 'error',
        self::TYPE_MESSAGE => 'mail',
        self::TYPE_UPDATE => 'info',
        self::TYPE_REMINDER => 'schedule',
    ];

    public function __construct(PDO $db, string $basePath = '')
    {
        $this->db = $db;
        $this->basePath = $basePath;
    }

    /**
     * Create a new notification for a member
     */
    public function create(
        int $memberId,
        int $brigadeId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $link = null,
        ?array $data = null
    ): int {
        // Validate type
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Invalid notification type: {$type}");
        }

        // Prepend base path to link if it's a relative URL
        if ($link !== null && !empty($this->basePath) && strpos($link, '/') === 0 && strpos($link, $this->basePath) !== 0) {
            $link = $this->basePath . $link;
        }

        $stmt = $this->db->prepare("
            INSERT INTO notifications (member_id, brigade_id, type, title, body, link, data, created_at)
            VALUES (:member_id, :brigade_id, :type, :title, :body, :link, :data, datetime('now'))
        ");

        $stmt->execute([
            'member_id' => $memberId,
            'brigade_id' => $brigadeId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'data' => $data !== null ? json_encode($data) : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Create notifications for multiple members
     */
    public function createForMembers(
        array $memberIds,
        int $brigadeId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $link = null,
        ?array $data = null
    ): array {
        $ids = [];
        foreach ($memberIds as $memberId) {
            $ids[] = $this->create($memberId, $brigadeId, $type, $title, $body, $link, $data);
        }
        return $ids;
    }

    /**
     * Get paginated notifications for a member
     */
    public function getForMember(int $memberId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT id, type, title, body, link, data, read_at, created_at
            FROM notifications
            WHERE member_id = :member_id
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ");

        $stmt->bindValue('member_id', $memberId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse JSON data and add type metadata
        foreach ($notifications as &$notification) {
            if ($notification['data']) {
                $notification['data'] = json_decode($notification['data'], true);
            }
            $notification['color'] = self::TYPE_COLORS[$notification['type']] ?? '#757575';
            $notification['icon'] = self::TYPE_ICONS[$notification['type']] ?? 'notifications';
            $notification['is_read'] = $notification['read_at'] !== null;
        }

        return $notifications;
    }

    /**
     * Get total count of notifications for a member
     */
    public function getCountForMember(int $memberId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM notifications WHERE member_id = :member_id
        ");
        $stmt->execute(['member_id' => $memberId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Get count of unread notifications for a member
     */
    public function getUnreadCount(int $memberId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM notifications
            WHERE member_id = :member_id AND read_at IS NULL
        ");
        $stmt->execute(['member_id' => $memberId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Mark a single notification as read
     */
    public function markAsRead(int $notificationId, int $memberId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE notifications
            SET read_at = datetime('now')
            WHERE id = :id AND member_id = :member_id AND read_at IS NULL
        ");
        $stmt->execute([
            'id' => $notificationId,
            'member_id' => $memberId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark all notifications as read for a member
     */
    public function markAllAsRead(int $memberId): int
    {
        $stmt = $this->db->prepare("
            UPDATE notifications
            SET read_at = datetime('now')
            WHERE member_id = :member_id AND read_at IS NULL
        ");
        $stmt->execute(['member_id' => $memberId]);
        return $stmt->rowCount();
    }

    /**
     * Delete a single notification
     */
    public function delete(int $notificationId, int $memberId): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM notifications
            WHERE id = :id AND member_id = :member_id
        ");
        $stmt->execute([
            'id' => $notificationId,
            'member_id' => $memberId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Clear all notifications for a member
     */
    public function clearAll(int $memberId): int
    {
        $stmt = $this->db->prepare("
            DELETE FROM notifications WHERE member_id = :member_id
        ");
        $stmt->execute(['member_id' => $memberId]);
        return $stmt->rowCount();
    }

    /**
     * Delete notifications older than specified days (for cleanup job)
     */
    public function deleteOlderThan(int $days = 30): int
    {
        $stmt = $this->db->prepare("
            DELETE FROM notifications
            WHERE created_at < datetime('now', :days || ' days')
        ");
        $stmt->execute(['days' => "-{$days}"]);
        return $stmt->rowCount();
    }

    /**
     * Get a single notification by ID
     */
    public function getById(int $notificationId, int $memberId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, type, title, body, link, data, read_at, created_at
            FROM notifications
            WHERE id = :id AND member_id = :member_id
        ");
        $stmt->execute([
            'id' => $notificationId,
            'member_id' => $memberId,
        ]);

        $notification = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$notification) {
            return null;
        }

        if ($notification['data']) {
            $notification['data'] = json_decode($notification['data'], true);
        }
        $notification['color'] = self::TYPE_COLORS[$notification['type']] ?? '#757575';
        $notification['icon'] = self::TYPE_ICONS[$notification['type']] ?? 'notifications';
        $notification['is_read'] = $notification['read_at'] !== null;

        return $notification;
    }
}
