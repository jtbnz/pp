<?php
declare(strict_types=1);

namespace Portal\Models;

use Portal\Services\HolidayService;
use PDO;
use DateTime;
use DateTimeZone;

/**
 * LeaveRequest Model
 *
 * Handles all database operations for leave requests.
 * Status values: pending, approved, denied
 */
class LeaveRequest
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
     * Find a leave request by ID
     *
     * @param int $id
     * @return array|null
     */
    public function findById(int $id): ?array
    {
        $sql = "
            SELECT lr.*,
                   m.name as member_name,
                   m.email as member_email,
                   m.brigade_id,
                   d.name as decided_by_name
            FROM leave_requests lr
            INNER JOIN members m ON lr.member_id = m.id
            LEFT JOIN members d ON lr.decided_by = d.id
            WHERE lr.id = ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);

        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Find all leave requests for a member
     *
     * @param int $memberId
     * @return array
     */
    public function findByMember(int $memberId): array
    {
        $sql = "
            SELECT lr.*,
                   d.name as decided_by_name
            FROM leave_requests lr
            LEFT JOIN members d ON lr.decided_by = d.id
            WHERE lr.member_id = ?
            ORDER BY lr.training_date DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$memberId]);

        return $stmt->fetchAll();
    }

    /**
     * Find all pending leave requests for a brigade
     *
     * @param int $brigadeId
     * @return array
     */
    public function findPending(int $brigadeId): array
    {
        $sql = "
            SELECT lr.*,
                   m.name as member_name,
                   m.email as member_email,
                   m.rank as member_rank
            FROM leave_requests lr
            INNER JOIN members m ON lr.member_id = m.id
            WHERE m.brigade_id = ?
                AND lr.status = 'pending'
                AND lr.training_date >= date('now')
            ORDER BY lr.training_date ASC, lr.requested_at ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$brigadeId]);

        return $stmt->fetchAll();
    }

    /**
     * Find all leave requests for a specific training date
     *
     * @param string $date Training date (Y-m-d format)
     * @param int $brigadeId
     * @return array
     */
    public function findByTrainingDate(string $date, int $brigadeId): array
    {
        $sql = "
            SELECT lr.*,
                   m.name as member_name,
                   m.email as member_email,
                   m.rank as member_rank,
                   d.name as decided_by_name
            FROM leave_requests lr
            INNER JOIN members m ON lr.member_id = m.id
            LEFT JOIN members d ON lr.decided_by = d.id
            WHERE m.brigade_id = ?
                AND lr.training_date = ?
            ORDER BY m.name ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$brigadeId, $date]);

        return $stmt->fetchAll();
    }

    /**
     * Create a new leave request
     *
     * @param array $data
     * @return int The new leave request ID
     */
    public function create(array $data): int
    {
        $sql = "
            INSERT INTO leave_requests (member_id, training_date, reason, status, requested_at)
            VALUES (?, ?, ?, 'pending', datetime('now', 'localtime'))
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['member_id'],
            $data['training_date'],
            $data['reason'] ?? null,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Approve a leave request
     *
     * @param int $id
     * @param int $approvedBy Member ID who approved the request
     * @return bool
     */
    public function approve(int $id, int $approvedBy): bool
    {
        $sql = "
            UPDATE leave_requests
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
     * Deny a leave request
     *
     * @param int $id
     * @param int $deniedBy Member ID who denied the request
     * @return bool
     */
    public function deny(int $id, int $deniedBy): bool
    {
        $sql = "
            UPDATE leave_requests
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
     * Cancel a leave request (delete it)
     *
     * @param int $id
     * @return bool
     */
    public function cancel(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM leave_requests WHERE id = ? AND status = 'pending'");
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get upcoming training nights for a member to request leave
     * Returns trainings that the member hasn't already requested leave for
     * Automatically shifts training to Tuesday if Monday is a public holiday
     *
     * @param int $memberId
     * @param int $limit Maximum number of trainings to return
     * @return array
     */
    public function getUpcomingTrainings(int $memberId, int $limit = 3): array
    {
        // Get member's brigade info for training day
        $memberSql = "
            SELECT m.brigade_id, b.training_day, b.training_time
            FROM members m
            INNER JOIN brigades b ON m.brigade_id = b.id
            WHERE m.id = ?
        ";

        $stmt = $this->db->prepare($memberSql);
        $stmt->execute([$memberId]);
        $memberInfo = $stmt->fetch();

        if (!$memberInfo) {
            return [];
        }

        $brigadeId = (int)$memberInfo['brigade_id'];
        $trainingDay = (int)($memberInfo['training_day'] ?? 1); // Default to Monday
        $trainingTime = $memberInfo['training_time'] ?? '19:00';

        // Load the HolidayService to check for public holidays
        $holidayService = new HolidayService();

        // Get existing leave requests for this member (pending or approved)
        $existingSql = "
            SELECT training_date
            FROM leave_requests
            WHERE member_id = ?
                AND status IN ('pending', 'approved')
                AND training_date >= date('now')
        ";

        $stmt = $this->db->prepare($existingSql);
        $stmt->execute([$memberId]);
        $existingDates = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Check for any event exceptions (cancelled or moved trainings)
        $exceptionsSql = "
            SELECT ee.exception_date, ee.is_cancelled, ee.replacement_date
            FROM event_exceptions ee
            INNER JOIN events e ON ee.event_id = e.id
            WHERE e.brigade_id = ?
                AND e.is_training = 1
                AND ee.exception_date >= date('now')
        ";

        $stmt = $this->db->prepare($exceptionsSql);
        $stmt->execute([$brigadeId]);
        $exceptions = [];
        while ($row = $stmt->fetch()) {
            $exceptions[$row['exception_date']] = $row;
        }

        // Generate upcoming training dates
        $trainings = [];
        $dayMap = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'];
        $targetDay = $dayMap[$trainingDay] ?? 'Monday';

        // Start from today
        $date = new DateTime('now', new DateTimeZone('Pacific/Auckland'));

        // Find the next training day (the regular day, e.g., Monday)
        while ($date->format('l') !== $targetDay) {
            $date->modify('+1 day');
        }

        // If today is training day but it's already past training time, move to next week
        if ($date->format('Y-m-d') === date('Y-m-d')) {
            $now = new DateTime('now', new DateTimeZone('Pacific/Auckland'));
            $trainingDateTime = new DateTime($date->format('Y-m-d') . ' ' . $trainingTime, new DateTimeZone('Pacific/Auckland'));
            if ($now >= $trainingDateTime) {
                $date->modify('+7 days');
            }
        }

        $count = 0;
        $maxIterations = 20; // Safety limit
        $iterations = 0;

        while ($count < $limit && $iterations < $maxIterations) {
            $dateStr = $date->format('Y-m-d');
            $iterations++;

            // Check if this training is cancelled via event exception
            if (isset($exceptions[$dateStr]) && $exceptions[$dateStr]['is_cancelled']) {
                // Check for replacement date
                if (!empty($exceptions[$dateStr]['replacement_date'])) {
                    $replacementDate = $exceptions[$dateStr]['replacement_date'];
                    if (!in_array($replacementDate, $existingDates, true)) {
                        $trainings[] = [
                            'date' => $replacementDate,
                            'time' => $trainingTime,
                            'day_name' => (new DateTime($replacementDate))->format('l'),
                            'is_rescheduled' => true,
                            'original_date' => $dateStr,
                        ];
                        $count++;
                    }
                }
                $date->modify('+7 days');
                continue;
            }

            // Check if the regular training day is a public holiday
            // If so, shift to the next day (e.g., Monday -> Tuesday)
            $actualTrainingDate = $dateStr;
            $actualDayName = $date->format('l');
            $isMovedForHoliday = false;
            $holidayName = null;

            if ($holidayService->isPublicHoliday($dateStr)) {
                // Move to the next day (e.g., Tuesday)
                $shiftedDate = clone $date;
                $shiftedDate->modify('+1 day');
                $actualTrainingDate = $shiftedDate->format('Y-m-d');
                $actualDayName = $shiftedDate->format('l');
                $isMovedForHoliday = true;
                $holidayName = $holidayService->getHolidayName($dateStr);
            }

            // Skip if member already has a leave request for the actual training date
            if (in_array($actualTrainingDate, $existingDates, true)) {
                $date->modify('+7 days');
                continue;
            }

            $trainings[] = [
                'date' => $actualTrainingDate,
                'time' => $trainingTime,
                'day_name' => $actualDayName,
                'is_rescheduled' => $isMovedForHoliday,
                'original_date' => $isMovedForHoliday ? $dateStr : null,
                'move_reason' => $holidayName,
            ];
            $count++;

            $date->modify('+7 days');
        }

        return $trainings;
    }

    /**
     * Count active (pending or approved) requests for a member
     *
     * @param int $memberId
     * @return int
     */
    public function countActiveRequests(int $memberId): int
    {
        $sql = "
            SELECT COUNT(*)
            FROM leave_requests
            WHERE member_id = ?
                AND status IN ('pending', 'approved')
                AND training_date >= date('now')
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$memberId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Check if a leave request already exists for a member on a date
     *
     * @param int $memberId
     * @param string $date
     * @return bool
     */
    public function existsForDate(int $memberId, string $date): bool
    {
        $sql = "SELECT 1 FROM leave_requests WHERE member_id = ? AND training_date = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$memberId, $date]);

        return $stmt->fetch() !== false;
    }

    /**
     * Check if a leave request belongs to a specific member
     *
     * @param int $id Leave request ID
     * @param int $memberId
     * @return bool
     */
    public function belongsToMember(int $id, int $memberId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM leave_requests WHERE id = ? AND member_id = ?");
        $stmt->execute([$id, $memberId]);

        return $stmt->fetch() !== false;
    }

    /**
     * Check if a leave request belongs to a brigade
     *
     * @param int $id Leave request ID
     * @param int $brigadeId
     * @return bool
     */
    public function belongsToBrigade(int $id, int $brigadeId): bool
    {
        $sql = "
            SELECT 1
            FROM leave_requests lr
            INNER JOIN members m ON lr.member_id = m.id
            WHERE lr.id = ? AND m.brigade_id = ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id, $brigadeId]);

        return $stmt->fetch() !== false;
    }

    /**
     * Validate leave request data
     *
     * @param array $data
     * @param int $memberId
     * @return array Validation errors (empty if valid)
     */
    public function validate(array $data, int $memberId): array
    {
        global $config;

        $errors = [];

        // Training date is required
        if (empty($data['training_date'])) {
            $errors['training_date'] = 'Training date is required';
        } else {
            // Validate date format
            $date = DateTime::createFromFormat('Y-m-d', $data['training_date']);
            if (!$date) {
                $errors['training_date'] = 'Invalid date format';
            } else {
                // Must be a future date
                $today = new DateTime('now', new DateTimeZone('Pacific/Auckland'));
                $today->setTime(0, 0, 0);
                $date->setTime(0, 0, 0);

                if ($date < $today) {
                    $errors['training_date'] = 'Cannot request leave for past dates';
                }

                // Check if request already exists
                if ($this->existsForDate($memberId, $data['training_date'])) {
                    $errors['training_date'] = 'Leave request already exists for this date';
                }
            }
        }

        // Check max pending requests
        $maxPending = (int)($config['leave']['max_pending'] ?? 3);
        $activeCount = $this->countActiveRequests($memberId);

        if ($activeCount >= $maxPending) {
            $errors['limit'] = "You can only have {$maxPending} pending or approved leave requests at a time";
        }

        // Check if reason is required
        $requireReason = (bool)($config['leave']['require_reason'] ?? false);
        if ($requireReason && empty($data['reason'])) {
            $errors['reason'] = 'Reason is required';
        }

        return $errors;
    }

    /**
     * Mark a leave request as synced to DLB
     *
     * @param int $id
     * @param int|null $dlbMusterId
     * @return bool
     */
    public function markSynced(int $id, ?int $dlbMusterId = null): bool
    {
        $sql = "
            UPDATE leave_requests
            SET synced_to_dlb = 1,
                dlb_muster_id = ?
            WHERE id = ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$dlbMusterId, $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get leave requests that need to be synced to DLB
     *
     * @param int $brigadeId
     * @return array
     */
    public function getUnsyncedApproved(int $brigadeId): array
    {
        $sql = "
            SELECT lr.*, m.name as member_name, m.email as member_email
            FROM leave_requests lr
            INNER JOIN members m ON lr.member_id = m.id
            WHERE m.brigade_id = ?
                AND lr.status = 'approved'
                AND lr.synced_to_dlb = 0
            ORDER BY lr.training_date ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$brigadeId]);

        return $stmt->fetchAll();
    }

    /**
     * Get recent leave activity for a member (for display on dashboard)
     *
     * @param int $memberId
     * @param int $limit
     * @return array
     */
    public function getRecentActivity(int $memberId, int $limit = 5): array
    {
        $sql = "
            SELECT lr.*, d.name as decided_by_name
            FROM leave_requests lr
            LEFT JOIN members d ON lr.decided_by = d.id
            WHERE lr.member_id = ?
            ORDER BY
                CASE WHEN lr.status = 'pending' THEN 0 ELSE 1 END,
                lr.training_date DESC
            LIMIT ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$memberId, $limit]);

        return $stmt->fetchAll();
    }

    /**
     * Get count of pending requests for a brigade (for badge/notification)
     *
     * @param int $brigadeId
     * @return int
     */
    public function countPending(int $brigadeId): int
    {
        $sql = "
            SELECT COUNT(*)
            FROM leave_requests lr
            INNER JOIN members m ON lr.member_id = m.id
            WHERE m.brigade_id = ?
                AND lr.status = 'pending'
                AND lr.training_date >= date('now')
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$brigadeId]);

        return (int)$stmt->fetchColumn();
    }
}
