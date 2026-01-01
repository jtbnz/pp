<?php
declare(strict_types=1);

/**
 * AuditLog Model
 *
 * Handles audit logging for brigade activities including member changes,
 * settings updates, leave approvals, and other administrative actions.
 */
class AuditLog
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        if ($db === null) {
            global $db;
            $this->db = $db;
        } else {
            $this->db = $db;
        }
    }

    /**
     * Log an action
     *
     * @param int $brigadeId Brigade ID
     * @param int|null $memberId Member who performed the action (null for system actions)
     * @param string $action Action type (e.g., 'member.invite', 'leave.approve', 'settings.update')
     * @param array $details Additional details about the action
     * @return int New log entry ID
     */
    public function log(int $brigadeId, ?int $memberId, string $action, array $details): int
    {
        $sql = "
            INSERT INTO audit_log (brigade_id, member_id, action, details, created_at, ip_address, user_agent)
            VALUES (?, ?, ?, ?, datetime('now', 'localtime'), ?, ?)
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $brigadeId,
            $memberId,
            $action,
            json_encode($details),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get recent audit log entries for a brigade
     *
     * @param int $brigadeId Brigade ID
     * @param int $limit Maximum number of entries to return
     * @return array Recent log entries with member names
     */
    public function getRecent(int $brigadeId, int $limit = 50): array
    {
        $sql = "
            SELECT al.*, m.name as member_name
            FROM audit_log al
            LEFT JOIN members m ON al.member_id = m.id
            WHERE al.brigade_id = ?
            ORDER BY al.created_at DESC
            LIMIT ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$brigadeId, $limit]);

        $results = $stmt->fetchAll();

        // Parse JSON details
        foreach ($results as &$row) {
            $row['details'] = json_decode($row['details'], true) ?? [];
        }

        return $results;
    }

    /**
     * Get audit log entries with filtering
     *
     * @param int $brigadeId Brigade ID
     * @param array $filters Optional filters: action, member_id, from_date, to_date, search
     * @return array Filtered log entries
     */
    public function find(int $brigadeId, array $filters = []): array
    {
        $sql = "
            SELECT al.*, m.name as member_name
            FROM audit_log al
            LEFT JOIN members m ON al.member_id = m.id
            WHERE al.brigade_id = ?
        ";

        $params = [$brigadeId];

        // Filter by action type
        if (!empty($filters['action'])) {
            $sql .= " AND al.action = ?";
            $params[] = $filters['action'];
        }

        // Filter by action prefix (e.g., 'member.' for all member-related actions)
        if (!empty($filters['action_prefix'])) {
            $sql .= " AND al.action LIKE ?";
            $params[] = $filters['action_prefix'] . '%';
        }

        // Filter by member
        if (!empty($filters['member_id'])) {
            $sql .= " AND al.member_id = ?";
            $params[] = $filters['member_id'];
        }

        // Filter by date range
        if (!empty($filters['from_date'])) {
            $sql .= " AND DATE(al.created_at) >= ?";
            $params[] = $filters['from_date'];
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND DATE(al.created_at) <= ?";
            $params[] = $filters['to_date'];
        }

        // Search in details JSON
        if (!empty($filters['search'])) {
            $sql .= " AND al.details LIKE ?";
            $params[] = '%' . $filters['search'] . '%';
        }

        $sql .= " ORDER BY al.created_at DESC";

        // Pagination
        if (isset($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];

            if (isset($filters['offset'])) {
                $sql .= " OFFSET ?";
                $params[] = (int)$filters['offset'];
            }
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $results = $stmt->fetchAll();

        // Parse JSON details
        foreach ($results as &$row) {
            $row['details'] = json_decode($row['details'], true) ?? [];
        }

        return $results;
    }

    /**
     * Count audit log entries with filtering
     *
     * @param int $brigadeId Brigade ID
     * @param array $filters Optional filters
     * @return int Count of matching entries
     */
    public function count(int $brigadeId, array $filters = []): int
    {
        $sql = "SELECT COUNT(*) FROM audit_log WHERE brigade_id = ?";
        $params = [$brigadeId];

        if (!empty($filters['action'])) {
            $sql .= " AND action = ?";
            $params[] = $filters['action'];
        }

        if (!empty($filters['action_prefix'])) {
            $sql .= " AND action LIKE ?";
            $params[] = $filters['action_prefix'] . '%';
        }

        if (!empty($filters['member_id'])) {
            $sql .= " AND member_id = ?";
            $params[] = $filters['member_id'];
        }

        if (!empty($filters['from_date'])) {
            $sql .= " AND DATE(created_at) >= ?";
            $params[] = $filters['from_date'];
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND DATE(created_at) <= ?";
            $params[] = $filters['to_date'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Get log entry by ID
     *
     * @param int $id Log entry ID
     * @return array|null Log entry or null if not found
     */
    public function findById(int $id): ?array
    {
        $sql = "
            SELECT al.*, m.name as member_name
            FROM audit_log al
            LEFT JOIN members m ON al.member_id = m.id
            WHERE al.id = ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);

        $result = $stmt->fetch();

        if ($result) {
            $result['details'] = json_decode($result['details'], true) ?? [];
        }

        return $result ?: null;
    }

    /**
     * Delete old audit log entries
     *
     * @param int $brigadeId Brigade ID
     * @param int $daysToKeep Number of days to keep logs
     * @return int Number of deleted entries
     */
    public function prune(int $brigadeId, int $daysToKeep = 365): int
    {
        $sql = "
            DELETE FROM audit_log
            WHERE brigade_id = ?
            AND created_at < datetime('now', '-' || ? || ' days')
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$brigadeId, $daysToKeep]);

        return $stmt->rowCount();
    }

    /**
     * Get human-readable description of an action
     *
     * @param string $action Action type
     * @param array $details Action details
     * @return string Human-readable description
     */
    public static function getActionDescription(string $action, array $details = []): string
    {
        $descriptions = [
            'member.invite' => 'Invited member: ' . ($details['email'] ?? 'unknown'),
            'member.update' => 'Updated member: ' . ($details['name'] ?? 'unknown'),
            'member.deactivate' => 'Deactivated member: ' . ($details['name'] ?? 'unknown'),
            'member.reactivate' => 'Reactivated member: ' . ($details['name'] ?? 'unknown'),
            'member.role_change' => 'Changed role for ' . ($details['name'] ?? 'unknown') . ' to ' . ($details['new_role'] ?? 'unknown'),
            'leave.approve' => 'Approved leave for ' . ($details['member_name'] ?? 'unknown'),
            'leave.deny' => 'Denied leave for ' . ($details['member_name'] ?? 'unknown'),
            'event.create' => 'Created event: ' . ($details['title'] ?? 'unknown'),
            'event.update' => 'Updated event: ' . ($details['title'] ?? 'unknown'),
            'event.delete' => 'Deleted event: ' . ($details['title'] ?? 'unknown'),
            'notice.create' => 'Created notice: ' . ($details['title'] ?? 'unknown'),
            'notice.update' => 'Updated notice: ' . ($details['title'] ?? 'unknown'),
            'notice.delete' => 'Deleted notice: ' . ($details['title'] ?? 'unknown'),
            'settings.update' => 'Updated brigade settings',
            'training.generate' => 'Generated training nights',
            'sync.members' => 'Synced members with DLB',
            'sync.musters' => 'Synced musters with DLB',
            'auth.login' => 'Logged in',
            'auth.logout' => 'Logged out',
        ];

        return $descriptions[$action] ?? $action;
    }

    /**
     * Get icon class for an action type
     *
     * @param string $action Action type
     * @return string Icon character or class
     */
    public static function getActionIcon(string $action): string
    {
        $prefix = explode('.', $action)[0] ?? '';

        $icons = [
            'member' => '&#128100;',    // Person
            'leave' => '&#128198;',     // Calendar
            'event' => '&#128197;',     // Calendar
            'notice' => '&#128240;',    // Newspaper
            'settings' => '&#9881;',    // Gear
            'training' => '&#127941;',  // Whistle
            'sync' => '&#128260;',      // Sync arrows
            'auth' => '&#128274;',      // Lock
        ];

        return $icons[$prefix] ?? '&#9679;';  // Default: bullet
    }
}
