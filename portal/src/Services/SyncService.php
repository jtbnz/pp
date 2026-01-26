<?php
declare(strict_types=1);

namespace Portal\Services;

use Portal\Exceptions\DlbApiException;
use PDO;

/**
 * Sync Service
 *
 * Handles synchronization between Puke Portal and DLB attendance system.
 * Manages leave requests, muster creation, and member synchronization.
 */
class SyncService
{
    private DlbClient $dlbClient;
    private PDO $db;

    /**
     * Create a new SyncService
     *
     * @param DlbClient $dlbClient DLB API client
     * @param PDO|null $db Database connection (uses global if not provided)
     */
    public function __construct(DlbClient $dlbClient, ?PDO $db = null)
    {
        $this->dlbClient = $dlbClient;

        if ($db === null) {
            global $db;
            $this->db = $db;
        } else {
            $this->db = $db;
        }
    }

    /**
     * Sync an approved leave request to DLB
     *
     * Sets the member's attendance status to 'L' (Leave) for the training
     * date in the leave request.
     *
     * @param array $leave Leave request data (must include training_date and member_id)
     * @return bool True on success
     * @throws DlbApiException
     */
    public function syncApprovedLeave(array $leave): bool
    {
        // Get the member's dlb_member_id
        $stmt = $this->db->prepare('SELECT dlb_member_id FROM members WHERE id = ?');
        $stmt->execute([$leave['member_id']]);
        $member = $stmt->fetch();

        if (!$member || empty($member['dlb_member_id'])) {
            $this->logSync('leave', (int)$leave['id'], 'failed', 'Member not linked to DLB');
            return false;
        }

        $dlbMemberId = (int)$member['dlb_member_id'];

        // Get training date from the leave request
        $trainingDate = $leave['training_date'] ?? null;

        if (empty($trainingDate)) {
            $this->logSync('leave', (int)$leave['id'], 'skipped', 'No training date specified');
            return true;
        }

        try {
            // Find or create muster for this date
            $muster = $this->dlbClient->findMusterByDate($trainingDate);

            if ($muster === null) {
                // Create invisible muster for this training date
                $muster = $this->dlbClient->createMuster($trainingDate, false);
            }

            // Set attendance status to Leave
            $notes = sprintf(
                'Approved leave - Portal #%d%s',
                $leave['id'],
                !empty($leave['reason']) ? ' - ' . $leave['reason'] : ''
            );

            $this->dlbClient->setAttendanceStatus(
                (int)$muster['id'],
                $dlbMemberId,
                'L',
                $notes
            );

            // Update the leave request to mark as synced
            $updateStmt = $this->db->prepare('
                UPDATE leave_requests
                SET synced_to_dlb = 1, dlb_muster_id = ?
                WHERE id = ?
            ');
            $updateStmt->execute([(int)$muster['id'], $leave['id']]);

            $this->logSync('leave', (int)$leave['id'], 'success', 'Synced to muster ' . $muster['id']);
            return true;

        } catch (DlbApiException $e) {
            $this->logSync('leave', (int)$leave['id'], 'failed', $e->getMessage());
            return false;
        }
    }

    /**
     * Create future musters in DLB for upcoming training nights
     *
     * Musters are created as invisible and will be revealed on training day.
     *
     * @param int $months Number of months ahead to generate
     * @return array Results with counts and any errors
     * @throws DlbApiException
     */
    public function createFutureMusters(int $months = 12): array
    {
        $results = [
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => []
        ];

        // Get training nights for the next N months
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime("+{$months} months"));

        $stmt = $this->db->prepare("
            SELECT id, date(start_time) as event_date
            FROM events
            WHERE is_training = 1
                AND date(start_time) >= ?
                AND date(start_time) <= ?
            ORDER BY start_time
        ");
        $stmt->execute([$startDate, $endDate]);
        $trainings = $stmt->fetchAll();

        foreach ($trainings as $training) {
            try {
                // Check if muster already exists
                $existing = $this->dlbClient->findMusterByDate($training['event_date']);

                if ($existing !== null) {
                    $results['skipped']++;
                    continue;
                }

                // Create invisible muster
                $this->dlbClient->createMuster($training['event_date'], false);
                $results['created']++;

            } catch (DlbApiException $e) {
                $results['failed']++;
                $results['errors'][] = "Date {$training['event_date']}: " . $e->getMessage();
            }
        }

        // Log the generation result
        $status = $results['failed'] === 0 ? 'success' : ($results['created'] > 0 ? 'partial' : 'failed');
        $details = sprintf(
            'Created: %d, Skipped: %d, Failed: %d',
            $results['created'],
            $results['skipped'],
            $results['failed']
        );

        $this->logSync('musters', 0, $status, $details);

        return $results;
    }

    /**
     * Reveal today's training muster in DLB
     *
     * Makes the muster visible in the DLB attendance UI.
     *
     * @return bool True if muster was revealed
     * @throws DlbApiException
     */
    public function revealTodaysMuster(): bool
    {
        $today = date('Y-m-d');

        // Check if today is a training day
        $stmt = $this->db->prepare("
            SELECT id FROM events
            WHERE is_training = 1
                AND date(start_time) = ?
            LIMIT 1
        ");
        $stmt->execute([$today]);

        if (!$stmt->fetch()) {
            $this->logSync('reveal', 0, 'skipped', 'No training scheduled for today');
            return false;
        }

        // Find muster for today
        $muster = $this->dlbClient->findMusterByDate($today);

        if ($muster === null) {
            // Create and immediately reveal muster
            $muster = $this->dlbClient->createMuster($today, true);
            $this->logSync('reveal', (int)$muster['id'], 'success', 'Created and revealed muster for today');
            return true;
        }

        // Already visible?
        if (!empty($muster['visible'])) {
            $this->logSync('reveal', (int)$muster['id'], 'skipped', 'Muster already visible');
            return true;
        }

        // Reveal the muster
        $this->dlbClient->setMusterVisibility((int)$muster['id'], true);
        $this->logSync('reveal', (int)$muster['id'], 'success', 'Revealed muster for today');

        return true;
    }

    /**
     * Sync members from DLB to local database
     *
     * Updates or creates local member records with dlb_member_id.
     *
     * @return array Results with counts and any errors
     * @throws DlbApiException
     */
    public function syncMembersToDlb(): array
    {
        $results = [
            'total' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'errors' => []
        ];

        // Get members from DLB
        $dlbMembers = $this->dlbClient->getMembers();
        $results['total'] = count($dlbMembers);

        // Create lookup by name (case-insensitive)
        $dlbByName = [];
        foreach ($dlbMembers as $dlbMember) {
            $key = strtolower(trim($dlbMember['name']));
            $dlbByName[$key] = $dlbMember;
        }

        // Get local members
        $stmt = $this->db->prepare('SELECT id, name, dlb_member_id FROM members WHERE status = ?');
        $stmt->execute(['active']);
        $localMembers = $stmt->fetchAll();

        // Update matches for local members
        $updateStmt = $this->db->prepare('UPDATE members SET dlb_member_id = ? WHERE id = ?');

        foreach ($localMembers as $local) {
            $key = strtolower(trim($local['name']));

            if (isset($dlbByName[$key])) {
                $dlbId = (int)$dlbByName[$key]['id'];

                // Update if different or not set
                if ((int)$local['dlb_member_id'] !== $dlbId) {
                    $updateStmt->execute([$dlbId, $local['id']]);
                }

                $results['matched']++;
            } else {
                $results['unmatched']++;
            }
        }

        // Log the sync result
        $status = $results['unmatched'] === 0 ? 'success' : 'partial';
        $details = sprintf(
            'Total DLB: %d, Matched: %d, Unmatched: %d',
            $results['total'],
            $results['matched'],
            $results['unmatched']
        );

        $this->logSync('members', 0, $status, $details);

        return $results;
    }

    /**
     * Get the last sync status for a given operation
     *
     * @param string $operation Operation type (leave, musters, reveal, members)
     * @return array|null Last sync log entry or null
     */
    public function getLastSyncStatus(string $operation): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM sync_logs
            WHERE operation = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$operation]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Get sync status summary
     *
     * @return array Status for each operation type
     */
    public function getSyncStatus(): array
    {
        $operations = ['leave', 'musters', 'reveal', 'members'];
        $status = [];

        foreach ($operations as $op) {
            $status[$op] = $this->getLastSyncStatus($op);
        }

        // Add DLB connection test
        try {
            $this->dlbClient->testConnection();
            $status['connection'] = ['status' => 'success', 'message' => 'Connected to DLB'];
        } catch (DlbApiException $e) {
            $status['connection'] = [
                'status' => 'failed',
                'message' => $e->getMessage(),
                'error_code' => $e->getApiErrorCode()
            ];
        }

        return $status;
    }

    /**
     * Log a sync operation
     *
     * @param string $operation Operation type
     * @param int $referenceId Related record ID (leave_id, muster_id, etc.)
     * @param string $status Result status (success, failed, partial, skipped)
     * @param string|null $details Additional details
     */
    private function logSync(string $operation, int $referenceId, string $status, ?string $details = null): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO sync_logs (operation, reference_id, status, details, created_at)
                VALUES (?, ?, ?, ?, datetime('now', 'localtime'))
            ");
            $stmt->execute([$operation, $referenceId, $status, $details]);
        } catch (PDOException $e) {
            // Log to error log if database logging fails
            error_log("Sync log failed: {$operation} - {$status} - {$details}. Error: " . $e->getMessage());
        }
    }

    /**
     * Get the DLB client instance
     *
     * @return DlbClient
     */
    public function getDlbClient(): DlbClient
    {
        return $this->dlbClient;
    }

    /**
     * Normalize a name by removing common rank prefixes for matching
     *
     * @param string $name Name to normalize
     * @return string Normalized name (lowercase, no rank prefix)
     */
    private function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));

        // Common NZ Fire rank prefixes to strip
        $rankPrefixes = [
            'cfo ', 'dcfo ', 'acfo ', 'so ', 'sso ', 'stn off ', 'station officer ',
            'ff ', 'qff ', 'sff ', 'firefighter ', 'qualified firefighter ',
            'senior firefighter ', 'chief fire officer ', 'deputy chief ',
            'assistant chief ', 'recruit ', 'trainee '
        ];

        foreach ($rankPrefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $name = substr($name, strlen($prefix));
                break;
            }
        }

        return trim($name);
    }

    /**
     * Publish a new member from Portal to DLB
     *
     * Creates the member in DLB and links them by updating dlb_member_id.
     *
     * @param int $memberId Local member ID
     * @param string $name Member name
     * @param string|null $rank Member rank (FF, QFF, etc.)
     * @return array Result with success status and dlb_member_id
     */
    public function publishMemberToDlb(int $memberId, string $name, ?string $rank = null): array
    {
        $result = [
            'success' => false,
            'dlb_member_id' => null,
            'error' => null
        ];

        try {
            // Extract clean name without rank prefix for DLB
            $cleanName = $this->normalizeName($name);
            // Capitalize first letter of each word
            $cleanName = ucwords($cleanName);

            // Create member in DLB (rank defaults to 'FF' if not provided)
            $dlbResult = $this->dlbClient->createMember(
                $cleanName,
                $rank ?? 'FF',
                true
            );

            $dlbMemberId = (int)($dlbResult['id'] ?? $dlbResult['member']['id'] ?? 0);

            if ($dlbMemberId > 0) {
                // Update local member with dlb_member_id
                $updateStmt = $this->db->prepare('UPDATE members SET dlb_member_id = ? WHERE id = ?');
                $updateStmt->execute([$dlbMemberId, $memberId]);

                $result['success'] = true;
                $result['dlb_member_id'] = $dlbMemberId;

                $this->logSync('publish', $memberId, 'success', "Published to DLB as member #{$dlbMemberId}");
            } else {
                $result['error'] = 'DLB did not return a member ID';
                $this->logSync('publish', $memberId, 'failed', 'No member ID returned from DLB');
            }

        } catch (DlbApiException $e) {
            $result['error'] = $e->getMessage();
            $this->logSync('publish', $memberId, 'failed', $e->getMessage());
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            $this->logSync('publish', $memberId, 'failed', $e->getMessage());
        }

        return $result;
    }

    /**
     * Import members from DLB into Portal
     *
     * Creates new member records for DLB members that don't exist locally.
     * Existing members (matched by name) are updated with dlb_member_id.
     *
     * @param int $brigadeId Brigade to import members into
     * @return array Results with counts and details
     * @throws DlbApiException
     */
    public function importMembersFromDlb(int $brigadeId): array
    {
        $results = [
            'total_dlb' => 0,
            'imported' => 0,
            'linked' => 0,
            'skipped' => 0,
            'errors' => [],
            'imported_members' => [],
            'linked_members' => [],
            'skipped_members' => []
        ];

        // Get members from DLB
        $dlbMembers = $this->dlbClient->getMembers();
        $results['total_dlb'] = count($dlbMembers);

        // Get existing local members for this brigade (by name and by dlb_member_id)
        $stmt = $this->db->prepare('SELECT id, name, email, dlb_member_id FROM members WHERE brigade_id = ?');
        $stmt->execute([$brigadeId]);
        $localMembers = $stmt->fetchAll();

        // Create lookup tables - both exact and normalized names
        $localByName = [];
        $localByNormalizedName = [];
        $localByDlbId = [];
        foreach ($localMembers as $local) {
            $exactKey = strtolower(trim($local['name']));
            $normalizedKey = $this->normalizeName($local['name']);
            $localByName[$exactKey] = $local;
            $localByNormalizedName[$normalizedKey] = $local;
            if (!empty($local['dlb_member_id'])) {
                $localByDlbId[(int)$local['dlb_member_id']] = $local;
            }
        }

        // Process each DLB member
        foreach ($dlbMembers as $dlbMember) {
            $dlbId = (int)$dlbMember['id'];
            $name = trim($dlbMember['name']);
            $nameKey = strtolower($name);
            $normalizedKey = $this->normalizeName($name);
            $rank = $dlbMember['rank'] ?? null;
            $isActive = !empty($dlbMember['is_active']);

            // Skip inactive DLB members
            if (!$isActive) {
                $results['skipped']++;
                $results['skipped_members'][] = ['name' => $name, 'reason' => 'Inactive in DLB'];
                continue;
            }

            // Check if already linked by dlb_member_id
            if (isset($localByDlbId[$dlbId])) {
                $results['skipped']++;
                $results['skipped_members'][] = ['name' => $name, 'reason' => 'Already linked'];
                continue;
            }

            // Check if exists by exact name match first
            $local = null;
            if (isset($localByName[$nameKey])) {
                $local = $localByName[$nameKey];
            } elseif (isset($localByNormalizedName[$normalizedKey])) {
                // Try normalized name match (strips rank prefixes like "CFO ", "FF ", etc.)
                $local = $localByNormalizedName[$normalizedKey];
            }

            if ($local !== null) {
                // Link existing member to DLB
                $updateStmt = $this->db->prepare('UPDATE members SET dlb_member_id = ?, rank = COALESCE(rank, ?) WHERE id = ?');
                $updateStmt->execute([$dlbId, $rank, $local['id']]);

                $results['linked']++;
                $results['linked_members'][] = ['name' => $name, 'local_id' => $local['id']];
                continue;
            }

            // Create new member (without email - they'll need to be invited separately)
            try {
                $insertStmt = $this->db->prepare("
                    INSERT INTO members (brigade_id, email, name, role, rank, status, dlb_member_id, created_at)
                    VALUES (?, ?, ?, 'firefighter', ?, 'active', ?, datetime('now', 'localtime'))
                ");

                // Generate placeholder email - member will need to update it
                $placeholderEmail = 'dlb_import_' . $dlbId . '@placeholder.local';

                $insertStmt->execute([
                    $brigadeId,
                    $placeholderEmail,
                    $name,
                    $rank,
                    $dlbId
                ]);

                $newId = (int)$this->db->lastInsertId();
                $results['imported']++;
                $results['imported_members'][] = ['name' => $name, 'local_id' => $newId, 'dlb_id' => $dlbId];

            } catch (PDOException $e) {
                $results['errors'][] = "Failed to import {$name}: " . $e->getMessage();
            }
        }

        // Log the import
        $status = empty($results['errors']) ? 'success' : 'partial';
        $details = sprintf(
            'DLB Total: %d, Imported: %d, Linked: %d, Skipped: %d',
            $results['total_dlb'],
            $results['imported'],
            $results['linked'],
            $results['skipped']
        );

        $this->logSync('import', 0, $status, $details);

        return $results;
    }
}
