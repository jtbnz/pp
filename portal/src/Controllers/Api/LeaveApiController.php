<?php
declare(strict_types=1);

namespace Portal\Controllers\Api;

use Portal\Models\LeaveRequest;
use PDOException;

/**
 * Leave API Controller
 *
 * Handles JSON API endpoints for leave requests.
 * All responses are JSON formatted.
 */
class LeaveApiController
{
    private LeaveRequest $leaveModel;

    public function __construct()
    {
        $this->leaveModel = new LeaveRequest();
    }

    /**
     * List leave requests
     * GET /api/leave
     *
     * For firefighters: returns their own requests
     * For officers+: can view all requests with optional filters
     */
    public function index(): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $memberId = (int)$user['id'];
        $brigadeId = (int)$user['brigade_id'];

        // Officers can view all pending requests
        if (hasRole('officer') && ($_GET['pending'] ?? false)) {
            $requests = $this->leaveModel->findPending($brigadeId);
            jsonResponse([
                'data' => $requests,
                'meta' => [
                    'total' => count($requests),
                    'pending_count' => count($requests),
                ],
            ]);
            return;
        }

        // Get specific training date
        if (!empty($_GET['date'])) {
            if (!hasRole('officer')) {
                jsonResponse(['error' => 'Forbidden'], 403);
                return;
            }

            $requests = $this->leaveModel->findByTrainingDate($_GET['date'], $brigadeId);
            jsonResponse([
                'data' => $requests,
                'meta' => [
                    'date' => $_GET['date'],
                    'total' => count($requests),
                ],
            ]);
            return;
        }

        // Default: return current user's requests
        $requests = $this->leaveModel->findByMember($memberId);
        $activeCount = $this->leaveModel->countActiveRequests($memberId);

        global $config;
        $maxPending = (int)($config['leave']['max_pending'] ?? 3);

        jsonResponse([
            'data' => $requests,
            'meta' => [
                'total' => count($requests),
                'active_count' => $activeCount,
                'max_pending' => $maxPending,
                'can_request_more' => $activeCount < $maxPending,
            ],
        ]);
    }

    /**
     * Get a single leave request
     * GET /api/leave/{id}
     */
    public function show(string $id): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $leaveId = (int)$id;
        $memberId = (int)$user['id'];
        $brigadeId = (int)$user['brigade_id'];

        $request = $this->leaveModel->findById($leaveId);

        if (!$request) {
            jsonResponse(['error' => 'Leave request not found'], 404);
            return;
        }

        // Check access: own request or officer viewing brigade request
        $isOwn = $request['member_id'] === $memberId;
        $isOfficerWithAccess = hasRole('officer') && $this->leaveModel->belongsToBrigade($leaveId, $brigadeId);

        if (!$isOwn && !$isOfficerWithAccess) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        jsonResponse(['data' => $request]);
    }

    /**
     * Get upcoming trainings available for leave requests
     * GET /api/leave/upcoming
     */
    public function upcoming(): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $memberId = (int)$user['id'];
        $limit = min((int)($_GET['limit'] ?? 3), 10);

        $trainings = $this->leaveModel->getUpcomingTrainings($memberId, $limit);
        $activeCount = $this->leaveModel->countActiveRequests($memberId);

        global $config;
        $maxPending = (int)($config['leave']['max_pending'] ?? 3);

        jsonResponse([
            'data' => $trainings,
            'meta' => [
                'active_count' => $activeCount,
                'max_pending' => $maxPending,
                'can_request_more' => $activeCount < $maxPending,
            ],
        ]);
    }

    /**
     * Create a new leave request
     * POST /api/leave
     */
    public function store(): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $memberId = (int)$user['id'];

        // Parse JSON body
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if ($data === null) {
            jsonResponse(['error' => 'Invalid JSON'], 400);
            return;
        }

        $requestData = [
            'training_date' => trim($data['training_date'] ?? ''),
            'reason' => isset($data['reason']) ? trim($data['reason']) : null,
        ];

        // Validate
        $errors = $this->leaveModel->validate($requestData, $memberId);

        if (!empty($errors)) {
            jsonResponse([
                'error' => 'Validation failed',
                'errors' => $errors,
            ], 422);
            return;
        }

        // Create the leave request
        $requestData['member_id'] = $memberId;
        $leaveId = $this->leaveModel->create($requestData);

        // Log the action
        $this->logAction('leave.request', 'leave_request', $leaveId, [
            'training_date' => $requestData['training_date'],
            'reason' => $requestData['reason'],
        ]);

        // Get the created request
        $request = $this->leaveModel->findById($leaveId);

        jsonResponse([
            'success' => true,
            'message' => 'Leave request submitted',
            'data' => $request,
        ], 201);
    }

    /**
     * Approve a leave request
     * PUT /api/leave/{id}/approve
     */
    public function approve(string $id): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        if (!hasRole('officer')) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $leaveId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];
        $approverId = (int)$user['id'];

        $request = $this->leaveModel->findById($leaveId);

        if (!$request || !$this->leaveModel->belongsToBrigade($leaveId, $brigadeId)) {
            jsonResponse(['error' => 'Leave request not found'], 404);
            return;
        }

        if ($request['status'] !== 'pending') {
            jsonResponse(['error' => 'Leave request is not pending'], 400);
            return;
        }

        $success = $this->leaveModel->approve($leaveId, $approverId);

        if ($success) {
            $this->logAction('leave.approve', 'leave_request', $leaveId, [
                'member_id' => $request['member_id'],
                'training_date' => $request['training_date'],
            ]);

            // Get updated request
            $request = $this->leaveModel->findById($leaveId);
        }

        jsonResponse([
            'success' => $success,
            'message' => $success ? 'Leave request approved' : 'Failed to approve leave request',
            'data' => $success ? $request : null,
        ]);
    }

    /**
     * Deny a leave request
     * PUT /api/leave/{id}/deny
     */
    public function deny(string $id): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        if (!hasRole('officer')) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $leaveId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];
        $denierId = (int)$user['id'];

        $request = $this->leaveModel->findById($leaveId);

        if (!$request || !$this->leaveModel->belongsToBrigade($leaveId, $brigadeId)) {
            jsonResponse(['error' => 'Leave request not found'], 404);
            return;
        }

        if ($request['status'] !== 'pending') {
            jsonResponse(['error' => 'Leave request is not pending'], 400);
            return;
        }

        $success = $this->leaveModel->deny($leaveId, $denierId);

        if ($success) {
            $this->logAction('leave.deny', 'leave_request', $leaveId, [
                'member_id' => $request['member_id'],
                'training_date' => $request['training_date'],
            ]);

            // Get updated request
            $request = $this->leaveModel->findById($leaveId);
        }

        jsonResponse([
            'success' => $success,
            'message' => $success ? 'Leave request denied' : 'Failed to deny leave request',
            'data' => $success ? $request : null,
        ]);
    }

    /**
     * Cancel/delete a leave request
     * DELETE /api/leave/{id}
     */
    public function destroy(string $id): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $leaveId = (int)$id;
        $memberId = (int)$user['id'];
        $brigadeId = (int)$user['brigade_id'];

        $request = $this->leaveModel->findById($leaveId);

        if (!$request) {
            jsonResponse(['error' => 'Leave request not found'], 404);
            return;
        }

        // Users can only cancel their own pending requests
        // Officers can cancel any pending request in their brigade
        $isOwn = $request['member_id'] === $memberId;
        $isOfficerWithAccess = hasRole('officer') && $this->leaveModel->belongsToBrigade($leaveId, $brigadeId);

        if (!$isOwn && !$isOfficerWithAccess) {
            jsonResponse(['error' => 'Cannot cancel this leave request'], 403);
            return;
        }

        if ($request['status'] !== 'pending') {
            jsonResponse(['error' => 'Only pending requests can be cancelled'], 400);
            return;
        }

        $success = $this->leaveModel->cancel($leaveId);

        if ($success) {
            $this->logAction('leave.cancel', 'leave_request', $leaveId, [
                'member_id' => $request['member_id'],
                'training_date' => $request['training_date'],
                'cancelled_by' => $memberId,
            ]);
        }

        jsonResponse([
            'success' => $success,
            'message' => $success ? 'Leave request cancelled' : 'Failed to cancel leave request',
        ]);
    }

    /**
     * Get pending count for badge display
     * GET /api/leave/pending-count
     */
    public function pendingCount(): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        if (!hasRole('officer')) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $brigadeId = (int)$user['brigade_id'];
        $count = $this->leaveModel->countPending($brigadeId);

        jsonResponse(['count' => $count]);
    }

    /**
     * Log an action to the audit log
     */
    private function logAction(string $action, string $entityType, int $entityId, array $details): void
    {
        global $db;

        $user = currentUser();

        try {
            $stmt = $db->prepare("
                INSERT INTO audit_log (brigade_id, member_id, action, entity_type, entity_id, details, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now', 'localtime'))
            ");

            $stmt->execute([
                $user['brigade_id'] ?? null,
                $user['id'] ?? null,
                $action,
                $entityType,
                $entityId,
                json_encode($details),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (PDOException $e) {
            error_log('Failed to log action: ' . $e->getMessage());
        }
    }
}
