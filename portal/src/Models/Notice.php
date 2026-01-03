<?php
declare(strict_types=1);

/**
 * Notice Model
 *
 * Handles all database operations for notices.
 * Notice types: standard, sticky, timed, urgent
 */
class Notice
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
     * Find all active notices for a brigade
     * Active = display_from <= now AND display_to >= now (or null for indefinite)
     * Ordered: sticky first, then urgent, then by created_at desc
     *
     * @param int $brigadeId
     * @return array
     */
    public function findActive(int $brigadeId): array
    {
        // Use current UTC time for comparison since dates are stored in UTC
        $nowUtc = nowUtc();

        $sql = "
            SELECT n.*, m.name as author_name
            FROM notices n
            LEFT JOIN members m ON n.author_id = m.id
            WHERE n.brigade_id = ?
                AND (n.display_from IS NULL OR n.display_from <= ?)
                AND (n.display_to IS NULL OR n.display_to >= ?)
            ORDER BY
                CASE WHEN n.type = 'sticky' THEN 0 ELSE 1 END,
                CASE WHEN n.type = 'urgent' THEN 0 ELSE 1 END,
                n.created_at DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$brigadeId, $nowUtc, $nowUtc]);

        return $stmt->fetchAll();
    }

    /**
     * Find a notice by ID
     *
     * @param int $id
     * @return array|null
     */
    public function findById(int $id): ?array
    {
        $sql = "
            SELECT n.*, m.name as author_name
            FROM notices n
            LEFT JOIN members m ON n.author_id = m.id
            WHERE n.id = ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);

        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Find all notices for a brigade with optional filters
     *
     * @param int $brigadeId
     * @param array $filters Optional filters: type, search, limit, offset
     * @return array
     */
    public function findAll(int $brigadeId, array $filters = []): array
    {
        $sql = "
            SELECT n.*, m.name as author_name
            FROM notices n
            LEFT JOIN members m ON n.author_id = m.id
            WHERE n.brigade_id = ?
        ";

        $params = [$brigadeId];

        // Filter by type
        if (!empty($filters['type'])) {
            $sql .= " AND n.type = ?";
            $params[] = $filters['type'];
        }

        // Search in title and content
        if (!empty($filters['search'])) {
            $sql .= " AND (n.title LIKE ? OR n.content LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // Filter active only - use UTC for comparison
        if (!empty($filters['active_only'])) {
            $nowUtc = nowUtc();
            $sql .= " AND (n.display_from IS NULL OR n.display_from <= '{$nowUtc}')";
            $sql .= " AND (n.display_to IS NULL OR n.display_to >= '{$nowUtc}')";
        }

        // Order by: sticky first, then urgent, then by created_at desc
        $sql .= " ORDER BY
            CASE WHEN n.type = 'sticky' THEN 0 ELSE 1 END,
            CASE WHEN n.type = 'urgent' THEN 0 ELSE 1 END,
            n.created_at DESC
        ";

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

        return $stmt->fetchAll();
    }

    /**
     * Count notices for a brigade with optional filters
     *
     * @param int $brigadeId
     * @param array $filters
     * @return int
     */
    public function count(int $brigadeId, array $filters = []): int
    {
        $sql = "SELECT COUNT(*) FROM notices WHERE brigade_id = ?";
        $params = [$brigadeId];

        if (!empty($filters['type'])) {
            $sql .= " AND type = ?";
            $params[] = $filters['type'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (title LIKE ? OR content LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($filters['active_only'])) {
            $nowUtc = nowUtc();
            $sql .= " AND (display_from IS NULL OR display_from <= '{$nowUtc}')";
            $sql .= " AND (display_to IS NULL OR display_to >= '{$nowUtc}')";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Create a new notice
     *
     * @param array $data
     * @return int The new notice ID
     */
    public function create(array $data): int
    {
        $nowUtc = nowUtc();

        // Convert display dates from local time to UTC for storage
        $displayFrom = isset($data['display_from']) ? toUtc($data['display_from']) : null;
        $displayTo = isset($data['display_to']) ? toUtc($data['display_to']) : null;

        $sql = "
            INSERT INTO notices (brigade_id, title, content, type, display_from, display_to, author_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['brigade_id'],
            $data['title'],
            $data['content'] ?? null,
            $data['type'] ?? 'standard',
            $displayFrom,
            $displayTo,
            $data['author_id'] ?? null,
            $nowUtc,
            $nowUtc,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update an existing notice
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        $allowedFields = ['title', 'content', 'type', 'display_from', 'display_to'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                // Convert display dates from local time to UTC for storage
                if (($field === 'display_from' || $field === 'display_to') && $data[$field] !== null) {
                    $params[] = toUtc($data[$field]);
                } else {
                    $params[] = $data[$field];
                }
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;

        $sql = "UPDATE notices SET " . implode(', ', $fields) . " WHERE id = ?";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Delete a notice
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM notices WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Check if a notice belongs to a brigade
     *
     * @param int $id
     * @param int $brigadeId
     * @return bool
     */
    public function belongsToBrigade(int $id, int $brigadeId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM notices WHERE id = ? AND brigade_id = ?");
        $stmt->execute([$id, $brigadeId]);

        return $stmt->fetch() !== false;
    }

    /**
     * Check if a notice is currently active (visible)
     * Dates stored in DB are UTC, so we compare against current UTC time.
     *
     * @param array $notice
     * @return bool
     */
    public function isActive(array $notice): bool
    {
        // Current UTC timestamp
        $nowUtc = strtotime(nowUtc());

        // Stored dates are in UTC
        $fromOk = empty($notice['display_from']) || strtotime($notice['display_from']) <= $nowUtc;
        $toOk = empty($notice['display_to']) || strtotime($notice['display_to']) >= $nowUtc;

        return $fromOk && $toOk;
    }

    /**
     * Get the remaining time for a timed notice in seconds
     * Returns null if no expiry or already expired.
     * Dates stored in DB are UTC.
     *
     * @param array $notice
     * @return int|null Seconds remaining, or null
     */
    public function getRemainingSeconds(array $notice): ?int
    {
        if (empty($notice['display_to'])) {
            return null;
        }

        // Stored date is UTC, compare with current UTC time
        $expiresAtUtc = strtotime($notice['display_to']);
        $nowUtc = strtotime(nowUtc());
        $remaining = $expiresAtUtc - $nowUtc;

        return $remaining > 0 ? $remaining : null;
    }

    /**
     * Validate notice data
     *
     * @param array $data
     * @return array Validation errors (empty if valid)
     */
    public function validate(array $data): array
    {
        $errors = [];

        if (empty($data['title'])) {
            $errors['title'] = 'Title is required';
        } elseif (strlen($data['title']) > 200) {
            $errors['title'] = 'Title must be 200 characters or less';
        }

        if (isset($data['type']) && !in_array($data['type'], ['standard', 'sticky', 'timed', 'urgent'], true)) {
            $errors['type'] = 'Invalid notice type';
        }

        // For timed notices, display_to is required
        if (isset($data['type']) && $data['type'] === 'timed' && empty($data['display_to'])) {
            $errors['display_to'] = 'End date is required for timed notices';
        }

        // Validate date formats
        if (!empty($data['display_from']) && !strtotime($data['display_from'])) {
            $errors['display_from'] = 'Invalid start date format';
        }

        if (!empty($data['display_to']) && !strtotime($data['display_to'])) {
            $errors['display_to'] = 'Invalid end date format';
        }

        // Ensure display_to is after display_from
        if (!empty($data['display_from']) && !empty($data['display_to'])) {
            if (strtotime($data['display_from']) > strtotime($data['display_to'])) {
                $errors['display_to'] = 'End date must be after start date';
            }
        }

        return $errors;
    }
}
