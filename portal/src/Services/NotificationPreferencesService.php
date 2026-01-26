<?php
declare(strict_types=1);

namespace Portal\Services;

use PDO;

/**
 * Notification Preferences Service
 *
 * Manages user notification preferences (opt-in/out per notification type).
 */
class NotificationPreferencesService
{
    private PDO $db;

    // Default preferences (all enabled)
    private const DEFAULTS = [
        'system_alerts' => true,
        'messages' => true,
        'updates' => true,
        'reminders' => true,
    ];

    // Map notification types to preference columns
    private const TYPE_TO_PREF = [
        NotificationService::TYPE_SYSTEM_ALERT => 'system_alerts',
        NotificationService::TYPE_MESSAGE => 'messages',
        NotificationService::TYPE_UPDATE => 'updates',
        NotificationService::TYPE_REMINDER => 'reminders',
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get preferences for a member (creates defaults if not exists)
     */
    public function get(int $memberId): array
    {
        $stmt = $this->db->prepare("
            SELECT system_alerts, messages, updates, reminders, updated_at
            FROM notification_preferences
            WHERE member_id = :member_id
        ");
        $stmt->execute(['member_id' => $memberId]);
        $prefs = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prefs) {
            // Create default preferences
            $this->createDefaults($memberId);
            return array_merge(self::DEFAULTS, ['updated_at' => date('Y-m-d H:i:s')]);
        }

        // Convert to booleans
        return [
            'system_alerts' => (bool) $prefs['system_alerts'],
            'messages' => (bool) $prefs['messages'],
            'updates' => (bool) $prefs['updates'],
            'reminders' => (bool) $prefs['reminders'],
            'updated_at' => $prefs['updated_at'],
        ];
    }

    /**
     * Create default preferences for a member
     */
    private function createDefaults(int $memberId): void
    {
        $stmt = $this->db->prepare("
            INSERT OR IGNORE INTO notification_preferences
            (member_id, system_alerts, messages, updates, reminders, updated_at)
            VALUES (:member_id, 1, 1, 1, 1, datetime('now'))
        ");
        $stmt->execute(['member_id' => $memberId]);
    }

    /**
     * Update preferences for a member
     */
    public function update(int $memberId, array $preferences): bool
    {
        // Ensure defaults exist first
        $this->createDefaults($memberId);

        // Build update query for only valid fields
        $validFields = ['system_alerts', 'messages', 'updates', 'reminders'];
        $updates = [];
        $params = ['member_id' => $memberId];

        foreach ($validFields as $field) {
            if (array_key_exists($field, $preferences)) {
                $updates[] = "{$field} = :{$field}";
                $params[$field] = $preferences[$field] ? 1 : 0;
            }
        }

        if (empty($updates)) {
            return false;
        }

        $sql = "UPDATE notification_preferences SET " . implode(', ', $updates) . " WHERE member_id = :member_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Check if a member wants to receive a specific notification type
     */
    public function shouldSend(int $memberId, string $notificationType): bool
    {
        // Map type to preference column
        $prefColumn = self::TYPE_TO_PREF[$notificationType] ?? null;
        if ($prefColumn === null) {
            // Unknown type, allow by default
            return true;
        }

        $prefs = $this->get($memberId);
        return $prefs[$prefColumn] ?? true;
    }

    /**
     * Get members who want to receive a specific notification type
     * Returns array of member IDs
     */
    public function getMembersWhoWant(array $memberIds, string $notificationType): array
    {
        if (empty($memberIds)) {
            return [];
        }

        $prefColumn = self::TYPE_TO_PREF[$notificationType] ?? null;
        if ($prefColumn === null) {
            // Unknown type, return all members
            return $memberIds;
        }

        // Get members with explicit preferences
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->db->prepare("
            SELECT member_id, {$prefColumn} as wants
            FROM notification_preferences
            WHERE member_id IN ({$placeholders})
        ");
        $stmt->execute($memberIds);

        $prefsMap = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $prefsMap[$row['member_id']] = (bool) $row['wants'];
        }

        // Return members who want this type (or have no preference, meaning default enabled)
        $result = [];
        foreach ($memberIds as $memberId) {
            // Default is true if no preference exists
            if (!isset($prefsMap[$memberId]) || $prefsMap[$memberId]) {
                $result[] = $memberId;
            }
        }

        return $result;
    }
}
