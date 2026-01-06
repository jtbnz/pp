<?php
declare(strict_types=1);

require_once __DIR__ . '/DlbClient.php';

/**
 * Attendance Service
 *
 * Handles attendance data syncing from DLB and calculates attendance statistics
 * for member profiles including rolling 12-month percentages and position tracking.
 */
class AttendanceService
{
    private PDO $db;
    private ?DlbClient $dlbClient;
    private array $config;

    /**
     * Attendance thresholds for gauge colors
     */
    private const TRAINING_THRESHOLD = 20;  // 20% threshold for training attendance
    private const CALLOUT_THRESHOLD = 60;   // 60% threshold for call attendance

    public function __construct(PDO $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->dlbClient = null;
    }

    /**
     * Get or create DLB client
     */
    private function getDlbClient(): ?DlbClient
    {
        if ($this->dlbClient === null) {
            $dlbConfig = $this->config['dlb'] ?? [];
            if (!empty($dlbConfig['url']) && !empty($dlbConfig['token'])) {
                $this->dlbClient = new DlbClient(
                    $dlbConfig['url'],
                    $dlbConfig['token'],
                    (int)($dlbConfig['timeout'] ?? 30)
                );
            }
        }
        return $this->dlbClient;
    }

    /**
     * Get attendance statistics for a member (rolling 12 months)
     *
     * @param int $memberId Portal member ID
     * @return array Attendance stats including percentages and counts
     */
    public function getMemberStats(int $memberId): array
    {
        $fromDate = date('Y-m-d', strtotime('-12 months'));
        $toDate = date('Y-m-d');

        // Get all attendance records for this member in the period
        $stmt = $this->db->prepare("
            SELECT event_type, status, position, truck, event_date
            FROM attendance_records
            WHERE member_id = ?
              AND event_date >= ?
              AND event_date <= ?
            ORDER BY event_date DESC
        ");
        $stmt->execute([$memberId, $fromDate, $toDate]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate statistics
        $training = ['total' => 0, 'attended' => 0, 'leave' => 0, 'absent' => 0];
        $callout = ['total' => 0, 'attended' => 0, 'leave' => 0, 'absent' => 0];
        $positions = ['OIC' => 0, 'driver' => 0, 'crew' => 0];
        $trucks = [];

        foreach ($records as $record) {
            $type = $record['event_type'];
            $status = $record['status'];

            // Count by event type
            if ($type === 'training') {
                $training['total']++;
                if ($status === 'I') {
                    $training['attended']++;
                } elseif ($status === 'L') {
                    $training['leave']++;
                } else {
                    $training['absent']++;
                }
            } else {
                $callout['total']++;
                if ($status === 'I') {
                    $callout['attended']++;
                } elseif ($status === 'L') {
                    $callout['leave']++;
                } else {
                    $callout['absent']++;
                }
            }

            // Count positions (only for attended events)
            if ($status === 'I' && !empty($record['position'])) {
                $position = strtolower($record['position']);
                if (str_contains($position, 'oic') || str_contains($position, 'officer')) {
                    $positions['OIC']++;
                } elseif (str_contains($position, 'driver')) {
                    $positions['driver']++;
                } else {
                    $positions['crew']++;
                }
            }

            // Count trucks
            if ($status === 'I' && !empty($record['truck'])) {
                $truck = $record['truck'];
                $trucks[$truck] = ($trucks[$truck] ?? 0) + 1;
            }
        }

        // Calculate percentages (exclude leave from total for percentage calculation)
        $trainingEligible = $training['total'] - $training['leave'];
        $calloutEligible = $callout['total'] - $callout['leave'];

        $trainingPercent = $trainingEligible > 0
            ? round(($training['attended'] / $trainingEligible) * 100)
            : 0;

        $calloutPercent = $calloutEligible > 0
            ? round(($callout['attended'] / $calloutEligible) * 100)
            : 0;

        // Position percentages (of total attended)
        $totalAttended = $training['attended'] + $callout['attended'];
        $positionPercents = [];
        foreach ($positions as $pos => $count) {
            $positionPercents[$pos] = $totalAttended > 0
                ? round(($count / $totalAttended) * 100)
                : 0;
        }

        // Sort trucks by most used
        arsort($trucks);

        return [
            'training' => [
                'percent' => $trainingPercent,
                'threshold' => self::TRAINING_THRESHOLD,
                'above_threshold' => $trainingPercent >= self::TRAINING_THRESHOLD,
                'total' => $training['total'],
                'attended' => $training['attended'],
                'leave' => $training['leave'],
                'absent' => $training['absent'],
            ],
            'callout' => [
                'percent' => $calloutPercent,
                'threshold' => self::CALLOUT_THRESHOLD,
                'above_threshold' => $calloutPercent >= self::CALLOUT_THRESHOLD,
                'total' => $callout['total'],
                'attended' => $callout['attended'],
                'leave' => $callout['leave'],
                'absent' => $callout['absent'],
            ],
            'positions' => [
                'counts' => $positions,
                'percents' => $positionPercents,
            ],
            'trucks' => $trucks,
            'period' => [
                'from' => $fromDate,
                'to' => $toDate,
                'label' => 'Last 12 months',
            ],
        ];
    }

    /**
     * Get recent attendance events for a member
     *
     * @param int $memberId Portal member ID
     * @param int $limit Number of events to return (default 10)
     * @return array Recent attendance records
     */
    public function getRecentEvents(int $memberId, int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT
                ar.event_date,
                ar.event_type,
                ar.status,
                ar.position,
                ar.truck,
                ar.notes
            FROM attendance_records ar
            WHERE ar.member_id = ?
            ORDER BY ar.event_date DESC
            LIMIT ?
        ");
        $stmt->execute([$memberId, $limit]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get sync status for a brigade
     *
     * @param int $brigadeId Brigade ID
     * @return array|null Sync status or null if never synced
     */
    public function getSyncStatus(int $brigadeId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM attendance_sync
            WHERE brigade_id = ?
            ORDER BY last_sync_at DESC
            LIMIT 1
        ");
        $stmt->execute([$brigadeId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    /**
     * Sync attendance data from DLB for all members in a brigade
     *
     * @param int $brigadeId Brigade ID
     * @param bool $fullSync If true, sync all history (otherwise just recent)
     * @return array Sync result with counts
     */
    public function syncFromDlb(int $brigadeId, bool $fullSync = false): array
    {
        $client = $this->getDlbClient();
        if (!$client) {
            return [
                'success' => false,
                'error' => 'DLB integration not configured',
            ];
        }

        // Update sync status to 'syncing'
        $this->updateSyncStatus($brigadeId, 'syncing');

        try {
            // Determine date range
            $toDate = date('Y-m-d');
            if ($fullSync) {
                // Sync last 2 years for full sync
                $fromDate = date('Y-m-d', strtotime('-2 years'));
            } else {
                // Incremental sync: last sync date or 3 months
                $lastSync = $this->getSyncStatus($brigadeId);
                $fromDate = $lastSync && $lastSync['sync_to_date']
                    ? $lastSync['sync_to_date']
                    : date('Y-m-d', strtotime('-3 months'));
            }

            // Get all attendance history from DLB
            $history = $client->getAttendanceHistory($fromDate, $toDate);

            // Get member mapping (portal member ID to DLB member ID)
            $memberMap = $this->getMemberDlbMapping($brigadeId);

            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($history as $musterData) {
                $muster = $musterData['muster'];
                $attendance = $musterData['attendance'];
                $musterId = (int)$muster['id'];
                $eventDate = $muster['call_date'];
                $eventType = $this->determineEventType($muster);

                foreach ($attendance as $record) {
                    $dlbMemberId = (int)($record['member_id'] ?? 0);

                    // Find portal member for this DLB member
                    $portalMemberId = $memberMap[$dlbMemberId] ?? null;
                    if (!$portalMemberId) {
                        $skipped++;
                        continue;
                    }

                    // Upsert attendance record
                    $result = $this->upsertAttendanceRecord(
                        $portalMemberId,
                        $musterId,
                        $eventDate,
                        $eventType,
                        $record['status'] ?? 'A',
                        $record['position'] ?? null,
                        $record['truck'] ?? null,
                        $record['notes'] ?? null
                    );

                    if ($result === 'created') {
                        $created++;
                    } elseif ($result === 'updated') {
                        $updated++;
                    }
                }
            }

            // Update sync status to 'completed'
            $this->updateSyncStatus($brigadeId, 'completed', $fromDate, $toDate);

            return [
                'success' => true,
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'from_date' => $fromDate,
                'to_date' => $toDate,
            ];
        } catch (Exception $e) {
            $this->updateSyncStatus($brigadeId, 'failed', null, null, $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get mapping of DLB member IDs to portal member IDs
     *
     * @param int $brigadeId Brigade ID
     * @return array Map of dlb_member_id => portal_member_id
     */
    private function getMemberDlbMapping(int $brigadeId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, dlb_member_id
            FROM members
            WHERE brigade_id = ? AND dlb_member_id IS NOT NULL AND status = 'active'
        ");
        $stmt->execute([$brigadeId]);

        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[(int)$row['dlb_member_id']] = (int)$row['id'];
        }

        return $map;
    }

    /**
     * Upsert an attendance record
     *
     * @return string 'created', 'updated', or 'unchanged'
     */
    private function upsertAttendanceRecord(
        int $memberId,
        int $dlbMusterId,
        string $eventDate,
        string $eventType,
        string $status,
        ?string $position,
        ?string $truck,
        ?string $notes
    ): string {
        // Check if record exists
        $stmt = $this->db->prepare("
            SELECT id, status, position, truck
            FROM attendance_records
            WHERE member_id = ? AND dlb_muster_id = ?
        ");
        $stmt->execute([$memberId, $dlbMusterId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update if changed
            if ($existing['status'] !== $status ||
                $existing['position'] !== $position ||
                $existing['truck'] !== $truck) {
                $stmt = $this->db->prepare("
                    UPDATE attendance_records
                    SET status = ?, position = ?, truck = ?, notes = ?, updated_at = datetime('now', 'localtime')
                    WHERE id = ?
                ");
                $stmt->execute([$status, $position, $truck, $notes, $existing['id']]);
                return 'updated';
            }
            return 'unchanged';
        }

        // Create new record
        $stmt = $this->db->prepare("
            INSERT INTO attendance_records
                (member_id, dlb_muster_id, event_date, event_type, status, position, truck, notes, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'dlb')
        ");
        $stmt->execute([$memberId, $dlbMusterId, $eventDate, $eventType, $status, $position, $truck, $notes]);

        return 'created';
    }

    /**
     * Update sync status for a brigade
     */
    private function updateSyncStatus(
        int $brigadeId,
        string $status,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?string $errorMessage = null
    ): void {
        // Check if sync record exists
        $stmt = $this->db->prepare("SELECT id FROM attendance_sync WHERE brigade_id = ?");
        $stmt->execute([$brigadeId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $this->db->prepare("
                UPDATE attendance_sync
                SET status = ?,
                    last_sync_at = CASE WHEN ? = 'completed' THEN datetime('now', 'localtime') ELSE last_sync_at END,
                    sync_from_date = COALESCE(?, sync_from_date),
                    sync_to_date = COALESCE(?, sync_to_date),
                    error_message = ?
                WHERE brigade_id = ?
            ");
            $stmt->execute([$status, $status, $fromDate, $toDate, $errorMessage, $brigadeId]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO attendance_sync (brigade_id, status, last_sync_at, sync_from_date, sync_to_date, error_message)
                VALUES (?, ?, datetime('now', 'localtime'), ?, ?, ?)
            ");
            $stmt->execute([$brigadeId, $status, $fromDate, $toDate, $errorMessage]);
        }
    }

    /**
     * Determine event type from muster data
     */
    private function determineEventType(array $muster): string
    {
        $callType = strtolower($muster['call_type'] ?? '');
        $icadNumber = strtolower($muster['icad_number'] ?? '');

        if (str_contains($callType, 'training') || str_contains($icadNumber, 'muster')) {
            return 'training';
        }

        return 'callout';
    }

    /**
     * Format status code to human-readable text
     */
    public static function formatStatus(string $status): string
    {
        return match ($status) {
            'I' => 'Attended',
            'L' => 'On Leave',
            'A' => 'Absent',
            default => 'Unknown',
        };
    }

    /**
     * Format event type to human-readable text
     */
    public static function formatEventType(string $type): string
    {
        return match ($type) {
            'training' => 'Training',
            'callout' => 'Callout',
            default => ucfirst($type),
        };
    }

    /**
     * Format position to human-readable text
     */
    public static function formatPosition(?string $position): string
    {
        if (!$position) {
            return 'N/A';
        }

        $position = strtolower($position);
        if (str_contains($position, 'oic') || str_contains($position, 'officer')) {
            return 'OIC';
        }
        if (str_contains($position, 'driver')) {
            return 'Driver';
        }

        return 'Crew';
    }
}
