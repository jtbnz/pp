<?php
declare(strict_types=1);

/**
 * ExtendedLeaveRequest Model
 *
 * Handles extended (long-term) leave requests with date ranges.
 * Extended leave requires CFO approval only.
 * Status values: pending, approved, denied
 */
class ExtendedLeaveRequest
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
     * Find an extended leave request by ID
     *
     * @param int $id
     * @return array|null
     */
    public function findById(int $id): ?array
    {
        $sql = "
            SELECT elr.*,
                   m.name as member_name,
                   m.email as member_email,
                   m.brigade_id,
                   m.rank as member_rank,
                   d.name as decided_by_name,
                   d.rank as decided_by_rank
            FROM extended_leave_requests elr
            INNER JOIN members m ON elr.member_id = m.id
            LEFT JOIN members d ON elr.decided_by = d.id
            WHERE elr.id = ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);

        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Find all extended leave requests for a member
     *
     * @param int $memberId
     * @return array
     */
    public function findByMember(int $memberId): array
    {
        $sql = "
            SELECT elr.*,
                   d.name as decided_by_name
            FROM extended_leave_requests elr
            LEFT JOIN members d ON elr.decided_by = d.id
            WHERE elr.member_id = ?
            ORDER BY elr.start_date DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$memberId]);

        return $stmt->fetchAll();
    }

    /**
     * Find all pending extended leave requests for a brigade
     * These can only be approved by CFO
     *
     * @param int $brigadeId
     * @return array
     */
    public function findPending(int $brigadeId): array
    {
        $sql = "
            SELECT elr.*,
                   m.name as member_name,
                   m.email as member_email,
                   m.rank as member_rank
            FROM extended_leave_requests elr
            INNER JOIN members m ON elr.member_id = m.id
            WHERE m.brigade_id = ?
                AND elr.status = 'pending'
                AND elr.end_date >= date('now')
            ORDER BY elr.start_date ASC, elr.requested_at ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$brigadeId]);

        return $stmt->fetchAll();
    }

    /**
     * Create a new extended leave request
     *
     * @param array $data
     * @return int The new request ID
     */
    public function create(array $data): int
    {
        $sql = "
            INSERT INTO extended_leave_requests
                (member_id, start_date, end_date, reason, trainings_affected, status, requested_at)
            VALUES (?, ?, ?, ?, ?, 'pending', datetime('now', 'localtime'))
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['member_id'],
            $data['start_date'],
            $data['end_date'],
            $data['reason'] ?? null,
            $data['trainings_affected'] ?? 0,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Approve an extended leave request
     * Can only be done by CFO
     *
     * @param int $id
     * @param int $approvedBy Member ID who approved (must be CFO)
     * @return bool
     */
    public function approve(int $id, int $approvedBy): bool
    {
        $sql = "
            UPDATE extended_leave_requests
            SET status = 'approved',
                decided_by = ?,
                decided_at = datetime('now', 'localtime')
            WHERE id = ? AND status = 'pending'
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$approvedBy, $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Deny an extended leave request
     * Can only be done by CFO
     *
     * @param int $id
     * @param int $deniedBy Member ID who denied (must be CFO)
     * @return bool
     */
    public function deny(int $id, int $deniedBy): bool
    {
        $sql = "
            UPDATE extended_leave_requests
            SET status = 'denied',
                decided_by = ?,
                decided_at = datetime('now', 'localtime')
            WHERE id = ? AND status = 'pending'
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$deniedBy, $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Cancel an extended leave request (delete it)
     *
     * @param int $id
     * @return bool
     */
    public function cancel(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM extended_leave_requests WHERE id = ? AND status = 'pending'");
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Calculate how many trainings fall within a date range
     *
     * @param int $brigadeId
     * @param string $startDate
     * @param string $endDate
     * @return array Contains 'count' and 'dates' of trainings
     */
    public function calculateTrainingsInRange(int $brigadeId, string $startDate, string $endDate): array
    {
        // Get brigade training day
        $brigadeStmt = $this->db->prepare("SELECT training_day, training_time FROM brigades WHERE id = ?");
        $brigadeStmt->execute([$brigadeId]);
        $brigade = $brigadeStmt->fetch();

        if (!$brigade) {
            return ['count' => 0, 'dates' => []];
        }

        $trainingDay = (int)($brigade['training_day'] ?? 1); // 1 = Monday
        $trainingTime = $brigade['training_time'] ?? '19:00';

        // Map day number to name
        $dayMap = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'];
        $targetDay = $dayMap[$trainingDay] ?? 'Monday';

        // Get event exceptions (cancelled/moved trainings)
        $exceptionsSql = "
            SELECT ee.exception_date, ee.is_cancelled, ee.replacement_date
            FROM event_exceptions ee
            INNER JOIN events e ON ee.event_id = e.id
            WHERE e.brigade_id = ?
                AND e.is_training = 1
                AND ee.exception_date BETWEEN ? AND ?
        ";
        $exStmt = $this->db->prepare($exceptionsSql);
        $exStmt->execute([$brigadeId, $startDate, $endDate]);
        $exceptions = [];
        while ($row = $exStmt->fetch()) {
            $exceptions[$row['exception_date']] = $row;
        }

        // Calculate training dates in range
        $trainingDates = [];
        $start = new DateTime($startDate, new DateTimeZone('Pacific/Auckland'));
        $end = new DateTime($endDate, new DateTimeZone('Pacific/Auckland'));

        // Find first training day on or after start
        $current = clone $start;
        while ($current->format('l') !== $targetDay) {
            $current->modify('+1 day');
        }

        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');

            // Check if cancelled
            if (!isset($exceptions[$dateStr]) || !$exceptions[$dateStr]['is_cancelled']) {
                $trainingDates[] = [
                    'date' => $dateStr,
                    'day_name' => $current->format('l'),
                    'time' => $trainingTime,
                ];
            }

            // Check for replacement dates that fall in range
            if (isset($exceptions[$dateStr]) && !empty($exceptions[$dateStr]['replacement_date'])) {
                $replacement = $exceptions[$dateStr]['replacement_date'];
                if ($replacement >= $startDate && $replacement <= $endDate) {
                    $trainingDates[] = [
                        'date' => $replacement,
                        'day_name' => (new DateTime($replacement))->format('l'),
                        'time' => $trainingTime,
                        'is_rescheduled' => true,
                        'original_date' => $dateStr,
                    ];
                }
            }

            $current->modify('+7 days');
        }

        // Sort by date
        usort($trainingDates, fn($a, $b) => strcmp($a['date'], $b['date']));

        return [
            'count' => count($trainingDates),
            'dates' => $trainingDates,
        ];
    }

    /**
     * Check if a date range overlaps with existing extended leave requests
     *
     * @param int $memberId
     * @param string $startDate
     * @param string $endDate
     * @param int|null $excludeId Exclude this request ID (for updates)
     * @return bool
     */
    public function hasOverlappingRequest(int $memberId, string $startDate, string $endDate, ?int $excludeId = null): bool
    {
        $sql = "
            SELECT 1 FROM extended_leave_requests
            WHERE member_id = ?
                AND status IN ('pending', 'approved')
                AND (
                    (start_date <= ? AND end_date >= ?)
                    OR (start_date <= ? AND end_date >= ?)
                    OR (start_date >= ? AND end_date <= ?)
                )
        ";

        $params = [$memberId, $endDate, $startDate, $startDate, $startDate, $startDate, $endDate];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() !== false;
    }

    /**
     * Validate extended leave request data
     *
     * @param array $data
     * @param int $memberId
     * @return array Validation errors (empty if valid)
     */
    public function validate(array $data, int $memberId): array
    {
        $errors = [];

        // Start date is required
        if (empty($data['start_date'])) {
            $errors['start_date'] = 'Start date is required';
        } else {
            $startDate = DateTime::createFromFormat('Y-m-d', $data['start_date']);
            if (!$startDate) {
                $errors['start_date'] = 'Invalid start date format';
            } else {
                $today = new DateTime('now', new DateTimeZone('Pacific/Auckland'));
                $today->setTime(0, 0, 0);
                $startDate->setTime(0, 0, 0);

                if ($startDate < $today) {
                    $errors['start_date'] = 'Start date cannot be in the past';
                }
            }
        }

        // End date is required
        if (empty($data['end_date'])) {
            $errors['end_date'] = 'End date is required';
        } else {
            $endDate = DateTime::createFromFormat('Y-m-d', $data['end_date']);
            if (!$endDate) {
                $errors['end_date'] = 'Invalid end date format';
            }
        }

        // End date must be after start date
        if (empty($errors['start_date']) && empty($errors['end_date'])) {
            $startDate = new DateTime($data['start_date']);
            $endDate = new DateTime($data['end_date']);

            if ($endDate < $startDate) {
                $errors['end_date'] = 'End date must be after start date';
            }

            // Check for overlapping requests
            if ($this->hasOverlappingRequest($memberId, $data['start_date'], $data['end_date'])) {
                $errors['dates'] = 'You already have an extended leave request that overlaps with these dates';
            }
        }

        return $errors;
    }

    /**
     * Check if a request belongs to a member
     *
     * @param int $id
     * @param int $memberId
     * @return bool
     */
    public function belongsToMember(int $id, int $memberId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM extended_leave_requests WHERE id = ? AND member_id = ?");
        $stmt->execute([$id, $memberId]);

        return $stmt->fetch() !== false;
    }

    /**
     * Check if a request belongs to a brigade
     *
     * @param int $id
     * @param int $brigadeId
     * @return bool
     */
    public function belongsToBrigade(int $id, int $brigadeId): bool
    {
        $sql = "
            SELECT 1
            FROM extended_leave_requests elr
            INNER JOIN members m ON elr.member_id = m.id
            WHERE elr.id = ? AND m.brigade_id = ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id, $brigadeId]);

        return $stmt->fetch() !== false;
    }

    /**
     * Count pending extended leave requests for a brigade
     *
     * @param int $brigadeId
     * @return int
     */
    public function countPending(int $brigadeId): int
    {
        $sql = "
            SELECT COUNT(*)
            FROM extended_leave_requests elr
            INNER JOIN members m ON elr.member_id = m.id
            WHERE m.brigade_id = ?
                AND elr.status = 'pending'
                AND elr.end_date >= date('now')
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$brigadeId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Check if a specific training date falls within an approved extended leave period
     *
     * @param int $memberId
     * @param string $date Training date (Y-m-d)
     * @return array|null The extended leave request if found
     */
    public function getApprovedForDate(int $memberId, string $date): ?array
    {
        $sql = "
            SELECT *
            FROM extended_leave_requests
            WHERE member_id = ?
                AND status = 'approved'
                AND start_date <= ?
                AND end_date >= ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$memberId, $date, $date]);

        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get all extended leave requests (for admin view)
     *
     * @param int $brigadeId
     * @param array $filters Optional filters: status, member_id
     * @return array
     */
    public function findAll(int $brigadeId, array $filters = []): array
    {
        $sql = "
            SELECT elr.*,
                   m.name as member_name,
                   m.email as member_email,
                   m.rank as member_rank,
                   d.name as decided_by_name
            FROM extended_leave_requests elr
            INNER JOIN members m ON elr.member_id = m.id
            LEFT JOIN members d ON elr.decided_by = d.id
            WHERE m.brigade_id = ?
        ";

        $params = [$brigadeId];

        if (!empty($filters['status'])) {
            $sql .= " AND elr.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['member_id'])) {
            $sql .= " AND elr.member_id = ?";
            $params[] = $filters['member_id'];
        }

        $sql .= " ORDER BY elr.start_date DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
