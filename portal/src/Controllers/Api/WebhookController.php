<?php
declare(strict_types=1);

namespace Portal\Controllers\Api;

use Portal\Services\AttendanceService;
use PDO;

/**
 * Webhook Controller
 *
 * Handles incoming webhooks from external systems (DLB).
 * These endpoints do NOT require user authentication but DO require
 * a valid webhook secret in the Authorization header.
 */
class WebhookController
{
    private PDO $db;
    private array $config;

    public function __construct()
    {
        global $db, $config;
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Validate the webhook secret from Authorization header
     */
    private function validateWebhookSecret(): bool
    {
        $webhookSecret = $this->config['dlb']['webhook_secret'] ?? '';

        if (empty($webhookSecret)) {
            return false;
        }

        // Check Authorization header (Bearer token)
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return hash_equals($webhookSecret, $matches[1]);
        }

        // Also check X-Webhook-Secret header as alternative
        $secretHeader = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
        if (!empty($secretHeader)) {
            return hash_equals($webhookSecret, $secretHeader);
        }

        return false;
    }

    /**
     * POST /api/webhook/attendance
     *
     * Receive attendance data from DLB when a callout/muster is saved or updated.
     *
     * Expected payload:
     * {
     *   "event": "callout.created" | "callout.updated" | "attendance.saved",
     *   "callout": {
     *     "id": 123,
     *     "icad_number": "ABC123",
     *     "call_type": "Structure Fire",
     *     "call_date": "2024-01-15",
     *     "visible": true,
     *     "status": "active" | "submitted" | "locked"
     *   },
     *   "attendance": [
     *     {
     *       "member_id": 45,
     *       "status": "I" | "L" | "A",
     *       "position": "OIC",
     *       "truck": "551"
     *     }
     *   ]
     * }
     */
    public function attendance(): void
    {
        // Validate webhook secret
        if (!$this->validateWebhookSecret()) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Parse request body
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            jsonResponse(['error' => 'Invalid JSON payload'], 400);
            return;
        }

        $event = $input['event'] ?? '';
        $callout = $input['callout'] ?? [];
        $attendance = $input['attendance'] ?? [];

        // Validate required fields
        if (empty($callout['id']) || empty($callout['call_date'])) {
            jsonResponse(['error' => 'Missing required callout data'], 400);
            return;
        }

        try {
            $attendanceService = new AttendanceService($this->db, $this->config);

            // Get member mapping (DLB member ID => Portal member ID)
            $brigadeId = $this->config['dlb']['brigade_id'] ?? 1;
            $memberMap = $this->getMemberDlbMapping($brigadeId);

            $created = 0;
            $updated = 0;
            $skipped = 0;

            $dlbMusterId = (int)$callout['id'];
            $eventDate = $callout['call_date'];
            $icadNumber = $callout['icad_number'] ?? null;
            $callType = $callout['call_type'] ?? null;

            // Determine event type
            $eventType = $this->determineEventType($callout);

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
                    $dlbMusterId,
                    $eventDate,
                    $eventType,
                    $record['status'] ?? 'A',
                    $record['position'] ?? null,
                    $record['truck'] ?? null,
                    null, // notes
                    $icadNumber,
                    $callType
                );

                if ($result === 'created') {
                    $created++;
                } elseif ($result === 'updated') {
                    $updated++;
                }
            }

            // Update sync status
            $this->updateSyncTimestamp($brigadeId);

            // Log the webhook
            $this->logWebhook($event, $dlbMusterId, $created, $updated, $skipped);

            jsonResponse([
                'success' => true,
                'event' => $event,
                'callout_id' => $dlbMusterId,
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
            ]);

        } catch (\Exception $e) {
            error_log("Webhook error: " . $e->getMessage());
            jsonResponse([
                'success' => false,
                'error' => 'Failed to process webhook',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get mapping of DLB member IDs to portal member IDs
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
     * Determine event type from callout data
     */
    private function determineEventType(array $callout): string
    {
        $callType = strtolower($callout['call_type'] ?? '');
        $icadNumber = strtolower($callout['icad_number'] ?? '');

        if (str_contains($callType, 'training') || str_contains($icadNumber, 'muster')) {
            return 'training';
        }

        return 'callout';
    }

    /**
     * Upsert an attendance record
     */
    private function upsertAttendanceRecord(
        int $memberId,
        int $dlbMusterId,
        string $eventDate,
        string $eventType,
        string $status,
        ?string $position,
        ?string $truck,
        ?string $notes,
        ?string $icadNumber,
        ?string $callType
    ): string {
        // Check if record exists
        $stmt = $this->db->prepare("
            SELECT id, status, position, truck, icad_number, call_type
            FROM attendance_records
            WHERE member_id = ? AND dlb_muster_id = ?
        ");
        $stmt->execute([$memberId, $dlbMusterId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update if changed
            if ($existing['status'] !== $status ||
                $existing['position'] !== $position ||
                $existing['truck'] !== $truck ||
                $existing['icad_number'] !== $icadNumber ||
                $existing['call_type'] !== $callType) {
                $stmt = $this->db->prepare("
                    UPDATE attendance_records
                    SET status = ?, position = ?, truck = ?, notes = ?, icad_number = ?, call_type = ?, updated_at = datetime('now', 'localtime')
                    WHERE id = ?
                ");
                $stmt->execute([$status, $position, $truck, $notes, $icadNumber, $callType, $existing['id']]);
                return 'updated';
            }
            return 'unchanged';
        }

        // Create new record
        $stmt = $this->db->prepare("
            INSERT INTO attendance_records
                (member_id, dlb_muster_id, event_date, event_type, status, position, truck, notes, icad_number, call_type, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'dlb_webhook')
        ");
        $stmt->execute([$memberId, $dlbMusterId, $eventDate, $eventType, $status, $position, $truck, $notes, $icadNumber, $callType]);

        return 'created';
    }

    /**
     * Update sync timestamp for brigade
     */
    private function updateSyncTimestamp(int $brigadeId): void
    {
        // Check if sync record exists
        $stmt = $this->db->prepare("SELECT id FROM attendance_sync WHERE brigade_id = ?");
        $stmt->execute([$brigadeId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $this->db->prepare("
                UPDATE attendance_sync
                SET last_sync_at = datetime('now', 'localtime'),
                    status = 'completed',
                    sync_to_date = date('now', 'localtime')
                WHERE brigade_id = ?
            ");
            $stmt->execute([$brigadeId]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO attendance_sync (brigade_id, status, last_sync_at, sync_to_date)
                VALUES (?, 'completed', datetime('now', 'localtime'), date('now', 'localtime'))
            ");
            $stmt->execute([$brigadeId]);
        }
    }

    /**
     * Log webhook receipt
     */
    private function logWebhook(string $event, int $calloutId, int $created, int $updated, int $skipped): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO audit_log (action, entity_type, entity_id, actor_id, ip_address, details)
            VALUES ('webhook.attendance', 'callout', ?, 0, ?, ?)
        ");
        $stmt->execute([
            $calloutId,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            json_encode([
                'event' => $event,
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
            ])
        ]);
    }
}
