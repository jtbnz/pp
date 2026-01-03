<?php
declare(strict_types=1);

/**
 * Member Model
 *
 * Handles all CRUD operations for members including service periods
 * for honors calculation.
 */
class Member
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Find a member by ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT m.*, b.name as brigade_name
            FROM members m
            LEFT JOIN brigades b ON m.brigade_id = b.id
            WHERE m.id = ?
        ');
        $stmt->execute([$id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Find a member by email within a brigade
     */
    public function findByEmail(string $email, int $brigadeId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM members
            WHERE email = ? AND brigade_id = ?
        ');
        $stmt->execute([$email, $brigadeId]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Find a member by email across all brigades
     */
    public function findByEmailGlobal(string $email): ?array
    {
        $stmt = $this->db->prepare('
            SELECT m.*, b.name as brigade_name, b.slug as brigade_slug
            FROM members m
            JOIN brigades b ON b.id = m.brigade_id
            WHERE LOWER(m.email) = LOWER(?)
            LIMIT 1
        ');
        $stmt->execute([$email]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Find a member by access token
     */
    public function findByAccessToken(string $token): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM members
            WHERE access_token = ?
            AND status = ?
            AND (access_expires IS NULL OR access_expires > datetime(\'now\'))
        ');
        $stmt->execute([$token, 'active']);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Update a member's access token
     *
     * @param int $id The member ID
     * @param string $token The new access token
     * @param \DateTime $expires When the token expires
     * @return bool Success
     */
    public function updateAccessToken(int $id, string $token, \DateTime $expires): bool
    {
        $stmt = $this->db->prepare('
            UPDATE members
            SET access_token = ?, access_expires = ?
            WHERE id = ?
        ');

        return $stmt->execute([
            $token,
            $expires->format('Y-m-d H:i:s'),
            $id
        ]);
    }

    /**
     * Update a member's PIN hash
     *
     * @param int $id The member ID
     * @param string|null $pinHash The hashed PIN (null to remove)
     * @return bool Success
     */
    public function updatePinHash(int $id, ?string $pinHash): bool
    {
        $stmt = $this->db->prepare('UPDATE members SET pin_hash = ? WHERE id = ?');
        return $stmt->execute([$pinHash, $id]);
    }

    /**
     * Find all members for a brigade with optional filters
     *
     * @param int $brigadeId
     * @param array $filters Optional filters: role, status, search
     * @return array
     */
    public function findByBrigade(int $brigadeId, array $filters = []): array
    {
        $sql = 'SELECT * FROM members WHERE brigade_id = ?';
        $params = [$brigadeId];

        // Filter by role
        if (!empty($filters['role'])) {
            $sql .= ' AND role = ?';
            $params[] = $filters['role'];
        }

        // Filter by status
        if (!empty($filters['status'])) {
            $sql .= ' AND status = ?';
            $params[] = $filters['status'];
        } else {
            // Default to active members only
            $sql .= ' AND status = ?';
            $params[] = 'active';
        }

        // Search by name or email
        if (!empty($filters['search'])) {
            $sql .= ' AND (name LIKE ? OR email LIKE ?)';
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // Ordering
        $orderBy = $filters['order_by'] ?? 'name';
        $orderDir = strtoupper($filters['order_dir'] ?? 'ASC');

        $allowedOrderBy = ['name', 'email', 'role', 'rank', 'created_at', 'last_login_at'];
        $orderBy = in_array($orderBy, $allowedOrderBy, true) ? $orderBy : 'name';
        $orderDir = $orderDir === 'DESC' ? 'DESC' : 'ASC';

        $sql .= " ORDER BY {$orderBy} {$orderDir}";

        // Pagination
        if (isset($filters['limit'])) {
            $sql .= ' LIMIT ?';
            $params[] = (int)$filters['limit'];

            if (isset($filters['offset'])) {
                $sql .= ' OFFSET ?';
                $params[] = (int)$filters['offset'];
            }
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Count members for a brigade with optional filters
     */
    public function countByBrigade(int $brigadeId, array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) FROM members WHERE brigade_id = ?';
        $params = [$brigadeId];

        if (!empty($filters['role'])) {
            $sql .= ' AND role = ?';
            $params[] = $filters['role'];
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND status = ?';
            $params[] = $filters['status'];
        } else {
            $sql .= ' AND status = ?';
            $params[] = 'active';
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (name LIKE ? OR email LIKE ?)';
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Create a new member
     *
     * @param array $data Member data
     * @return int New member ID
     */
    public function create(array $data): int
    {
        $requiredFields = ['brigade_id', 'email', 'name'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }

        // Validate role
        $validRoles = ['firefighter', 'officer', 'admin', 'superadmin'];
        $role = $data['role'] ?? 'firefighter';
        if (!in_array($role, $validRoles, true)) {
            throw new InvalidArgumentException("Invalid role: {$role}");
        }

        // Validate status
        $validStatuses = ['active', 'inactive'];
        $status = $data['status'] ?? 'active';
        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException("Invalid status: {$status}");
        }

        $sql = '
            INSERT INTO members (
                brigade_id, email, name, phone, role, rank, rank_date,
                status, access_token, access_expires, pin_hash
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['brigade_id'],
            strtolower(trim($data['email'])),
            trim($data['name']),
            $data['phone'] ?? null,
            $role,
            $data['rank'] ?? null,
            $data['rank_date'] ?? null,
            $status,
            $data['access_token'] ?? null,
            $data['access_expires'] ?? null,
            $data['pin_hash'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update a member
     *
     * @param int $id Member ID
     * @param array $data Updated data
     * @return bool Success
     */
    public function update(int $id, array $data): bool
    {
        // Build dynamic update query
        $updates = [];
        $params = [];

        $allowedFields = [
            'name', 'email', 'phone', 'role', 'rank', 'rank_date', 'status',
            'access_token', 'access_expires', 'pin_hash', 'push_subscription',
            'last_login_at'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updates)) {
            return false;
        }

        // Validate role if being updated
        if (isset($data['role'])) {
            $validRoles = ['firefighter', 'officer', 'admin', 'superadmin'];
            if (!in_array($data['role'], $validRoles, true)) {
                throw new InvalidArgumentException("Invalid role: {$data['role']}");
            }
        }

        // Validate status if being updated
        if (isset($data['status'])) {
            $validStatuses = ['active', 'inactive'];
            if (!in_array($data['status'], $validStatuses, true)) {
                throw new InvalidArgumentException("Invalid status: {$data['status']}");
            }
        }

        $sql = 'UPDATE members SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $params[] = $id;

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Deactivate a member (soft delete)
     */
    public function deactivate(int $id): bool
    {
        $stmt = $this->db->prepare('
            UPDATE members SET status = ?, access_token = NULL WHERE id = ?
        ');
        return $stmt->execute(['inactive', $id]);
    }

    /**
     * Reactivate a member
     */
    public function reactivate(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE members SET status = ? WHERE id = ?');
        return $stmt->execute(['active', $id]);
    }

    /**
     * Get all service periods for a member
     */
    public function getServicePeriods(int $memberId): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM service_periods
            WHERE member_id = ?
            ORDER BY start_date ASC
        ');
        $stmt->execute([$memberId]);

        return $stmt->fetchAll();
    }

    /**
     * Add a service period
     *
     * @param int $memberId Member ID
     * @param array $data Period data with start_date, optional end_date and notes
     * @return int New period ID
     */
    public function addServicePeriod(int $memberId, array $data): int
    {
        if (empty($data['start_date'])) {
            throw new InvalidArgumentException('start_date is required');
        }

        // Validate date format
        if (!$this->isValidDate($data['start_date'])) {
            throw new InvalidArgumentException('Invalid start_date format');
        }

        if (!empty($data['end_date']) && !$this->isValidDate($data['end_date'])) {
            throw new InvalidArgumentException('Invalid end_date format');
        }

        // Check for overlapping periods
        if ($this->hasOverlappingPeriod($memberId, $data['start_date'], $data['end_date'] ?? null)) {
            throw new InvalidArgumentException('Service period overlaps with existing period');
        }

        $stmt = $this->db->prepare('
            INSERT INTO service_periods (member_id, start_date, end_date, notes)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $memberId,
            $data['start_date'],
            $data['end_date'] ?? null,
            $data['notes'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update a service period
     */
    public function updateServicePeriod(int $id, array $data): bool
    {
        // Get existing period to check membership
        $stmt = $this->db->prepare('SELECT * FROM service_periods WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            return false;
        }

        $updates = [];
        $params = [];

        if (array_key_exists('start_date', $data)) {
            if (!$this->isValidDate($data['start_date'])) {
                throw new InvalidArgumentException('Invalid start_date format');
            }
            $updates[] = 'start_date = ?';
            $params[] = $data['start_date'];
        }

        if (array_key_exists('end_date', $data)) {
            if ($data['end_date'] !== null && !$this->isValidDate($data['end_date'])) {
                throw new InvalidArgumentException('Invalid end_date format');
            }
            $updates[] = 'end_date = ?';
            $params[] = $data['end_date'];
        }

        if (array_key_exists('notes', $data)) {
            $updates[] = 'notes = ?';
            $params[] = $data['notes'];
        }

        if (empty($updates)) {
            return false;
        }

        $sql = 'UPDATE service_periods SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $params[] = $id;

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Delete a service period
     */
    public function deleteServicePeriod(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM service_periods WHERE id = ?');
        return $stmt->execute([$id]);
    }

    /**
     * Get a single service period by ID
     */
    public function getServicePeriod(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM service_periods WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Calculate total service days for a member
     *
     * @param int $memberId
     * @return int Total days of service
     */
    public function calculateTotalService(int $memberId): int
    {
        $periods = $this->getServicePeriods($memberId);
        $totalDays = 0;

        foreach ($periods as $period) {
            $startDate = new DateTimeImmutable($period['start_date']);
            $endDate = $period['end_date']
                ? new DateTimeImmutable($period['end_date'])
                : new DateTimeImmutable('today');

            $diff = $startDate->diff($endDate);
            $totalDays += $diff->days;
        }

        return $totalDays;
    }

    /**
     * Calculate service for honors (years and months)
     */
    public function calculateServiceForHonors(int $memberId): array
    {
        $totalDays = $this->calculateTotalService($memberId);

        $years = (int)floor($totalDays / 365);
        $remainingDays = $totalDays % 365;
        $months = (int)floor($remainingDays / 30);
        $days = $remainingDays % 30;

        return [
            'total_days' => $totalDays,
            'years' => $years,
            'months' => $months,
            'days' => $days,
            'display' => $years > 0
                ? "{$years} year" . ($years !== 1 ? 's' : '') . ", {$months} month" . ($months !== 1 ? 's' : '')
                : "{$months} month" . ($months !== 1 ? 's' : '') . ", {$days} day" . ($days !== 1 ? 's' : '')
        ];
    }

    /**
     * Check if member belongs to a brigade
     */
    public function belongsToBrigade(int $memberId, int $brigadeId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM members WHERE id = ? AND brigade_id = ?');
        $stmt->execute([$memberId, $brigadeId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Update last login timestamp
     */
    public function updateLastLogin(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE members SET last_login_at = datetime(\'now\') WHERE id = ?');
        return $stmt->execute([$id]);
    }

    /**
     * Verify and set PIN
     */
    public function setPin(int $id, string $pin): bool
    {
        if (strlen($pin) !== 6 || !ctype_digit($pin)) {
            throw new InvalidArgumentException('PIN must be exactly 6 digits');
        }

        $hash = password_hash($pin, PASSWORD_DEFAULT);

        $stmt = $this->db->prepare('UPDATE members SET pin_hash = ? WHERE id = ?');
        return $stmt->execute([$hash, $id]);
    }

    /**
     * Verify a PIN
     */
    public function verifyPin(int $id, string $pin): bool
    {
        $stmt = $this->db->prepare('SELECT pin_hash FROM members WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch();

        if (!$result || !$result['pin_hash']) {
            return false;
        }

        return password_verify($pin, $result['pin_hash']);
    }

    /**
     * Get member with brigade information
     */
    public function getWithBrigade(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT m.*,
                   b.name as brigade_name,
                   b.slug as brigade_slug,
                   b.logo_url as brigade_logo
            FROM members m
            JOIN brigades b ON m.brigade_id = b.id
            WHERE m.id = ?
        ');
        $stmt->execute([$id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Get members expiring soon (within given days)
     */
    public function getExpiringAccess(int $brigadeId, int $withinDays = 30): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM members
            WHERE brigade_id = ?
            AND status = ?
            AND access_expires IS NOT NULL
            AND access_expires <= datetime(\'now\', ? || \' days\')
            AND access_expires > datetime(\'now\')
            ORDER BY access_expires ASC
        ');
        $stmt->execute([$brigadeId, 'active', (string)$withinDays]);

        return $stmt->fetchAll();
    }

    /**
     * Helper to validate date format (YYYY-MM-DD)
     */
    private function isValidDate(string $date): bool
    {
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * Check for overlapping service periods
     */
    private function hasOverlappingPeriod(int $memberId, string $startDate, ?string $endDate, ?int $excludeId = null): bool
    {
        $sql = '
            SELECT COUNT(*) FROM service_periods
            WHERE member_id = ?
            AND (
                (start_date <= ? AND (end_date IS NULL OR end_date >= ?))
                OR (start_date <= ? AND (end_date IS NULL OR end_date >= ?))
                OR (start_date >= ? AND (? IS NULL OR start_date <= ?))
            )
        ';
        $params = [
            $memberId,
            $startDate, $startDate,
            $endDate ?? '9999-12-31', $endDate ?? '9999-12-31',
            $startDate, $endDate, $endDate ?? '9999-12-31'
        ];

        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Get rank display name
     */
    public static function getRankDisplayName(string $rank): string
    {
        $ranks = [
            'CFO' => 'Chief Fire Officer',
            'DCFO' => 'Deputy Chief Fire Officer',
            'SSO' => 'Senior Station Officer',
            'SO' => 'Station Officer',
            'SFF' => 'Senior Firefighter',
            'QFF' => 'Qualified Firefighter',
            'FF' => 'Firefighter',
            'RCFF' => 'Recruit Firefighter'
        ];

        return $ranks[$rank] ?? $rank;
    }

    /**
     * Get role display name
     */
    public static function getRoleDisplayName(string $role): string
    {
        $roles = [
            'firefighter' => 'Firefighter',
            'officer' => 'Officer',
            'admin' => 'Admin',
            'superadmin' => 'Super Admin'
        ];

        return $roles[$role] ?? ucfirst($role);
    }

    /**
     * Get all valid roles
     */
    public static function getValidRoles(): array
    {
        return ['firefighter', 'officer', 'admin', 'superadmin'];
    }

    /**
     * Get all valid ranks
     */
    public static function getValidRanks(): array
    {
        return ['CFO', 'DCFO', 'SSO', 'SO', 'SFF', 'QFF', 'FF', 'RCFF'];
    }
}
