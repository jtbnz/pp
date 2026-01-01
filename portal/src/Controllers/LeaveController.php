<?php
declare(strict_types=1);

/**
 * Leave Controller
 *
 * Handles web routes for the leave request system.
 */
class LeaveController
{
    private LeaveRequest $leaveModel;

    public function __construct()
    {
        require_once __DIR__ . '/../Models/LeaveRequest.php';

        $this->leaveModel = new LeaveRequest();
    }

    /**
     * Display leave requests for current user
     * GET /leave
     */
    public function index(): void
    {
        $user = currentUser();

        if (!$user) {
            header('Location: /auth/login');
            exit;
        }

        $memberId = (int)$user['id'];
        $brigadeId = (int)$user['brigade_id'];

        // Get user's leave requests
        $leaveRequests = $this->leaveModel->findByMember($memberId);

        // Get upcoming trainings that can be requested
        $upcomingTrainings = $this->leaveModel->getUpcomingTrainings($memberId, 3);

        // Count active requests for the limit display
        $activeCount = $this->leaveModel->countActiveRequests($memberId);

        global $config;
        $maxPending = (int)($config['leave']['max_pending'] ?? 3);

        render('pages/leave/index', [
            'pageTitle' => 'Leave Requests',
            'leaveRequests' => $leaveRequests,
            'upcomingTrainings' => $upcomingTrainings,
            'activeCount' => $activeCount,
            'maxPending' => $maxPending,
            'canRequestMore' => $activeCount < $maxPending,
            'isOfficer' => hasRole('officer'),
        ]);
    }

    /**
     * Display pending leave requests for officers
     * GET /leave/pending
     */
    public function pending(): void
    {
        $user = currentUser();

        if (!$user) {
            header('Location: /auth/login');
            exit;
        }

        if (!hasRole('officer')) {
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        $brigadeId = (int)$user['brigade_id'];

        // Get all pending requests for the brigade
        $pendingRequests = $this->leaveModel->findPending($brigadeId);

        // Group by training date for better display
        $groupedRequests = [];
        foreach ($pendingRequests as $request) {
            $date = $request['training_date'];
            if (!isset($groupedRequests[$date])) {
                $groupedRequests[$date] = [];
            }
            $groupedRequests[$date][] = $request;
        }

        render('pages/leave/pending', [
            'pageTitle' => 'Pending Leave Requests',
            'pendingRequests' => $pendingRequests,
            'groupedRequests' => $groupedRequests,
            'pendingCount' => count($pendingRequests),
        ]);
    }

    /**
     * Display a single leave request
     * GET /leave/{id}
     */
    public function show(string $id): void
    {
        $user = currentUser();

        if (!$user) {
            header('Location: /auth/login');
            exit;
        }

        $requestId = (int)$id;
        $leaveRequest = $this->leaveModel->findById($requestId);

        if (!$leaveRequest) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Leave request not found']);
            return;
        }

        // Check if user has access to this request
        $isOwner = (int)$leaveRequest['member_id'] === (int)$user['id'];
        $isOfficer = hasRole('officer');
        $sameBrigade = (int)$leaveRequest['brigade_id'] === (int)$user['brigade_id'];

        if (!$sameBrigade || (!$isOwner && !$isOfficer)) {
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        render('pages/leave/show', [
            'pageTitle' => 'Leave Request Details',
            'leaveRequest' => $leaveRequest,
            'isOwner' => $isOwner,
            'isOfficer' => $isOfficer,
            'canApprove' => $isOfficer && $leaveRequest['status'] === 'pending',
        ]);
    }

    /**
     * Create a new leave request
     * POST /leave
     */
    public function store(): void
    {
        $user = currentUser();

        if (!$user) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Unauthorized'], 401);
            }
            header('Location: /auth/login');
            exit;
        }

        // Verify CSRF token for non-API requests
        if (!$this->isApiRequest() && !verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            http_response_code(403);
            echo 'Invalid CSRF token';
            return;
        }

        $memberId = (int)$user['id'];

        // Get request data
        $data = $this->getRequestData();

        // Validate
        $errors = $this->leaveModel->validate($data, $memberId);

        if (!empty($errors)) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Validation failed', 'errors' => $errors], 422);
                return;
            }

            $_SESSION['flash'] = ['type' => 'error', 'message' => reset($errors)];
            header('Location: /leave');
            exit;
        }

        // Create the leave request
        $data['member_id'] = $memberId;
        $leaveId = $this->leaveModel->create($data);

        // Log the action
        $this->logAction('leave.request', 'leave_request', $leaveId, [
            'training_date' => $data['training_date'],
            'reason' => $data['reason'] ?? null,
        ]);

        // Notify officers (TODO: implement push notification)
        $this->notifyOfficers($user, $data['training_date']);

        if ($this->isApiRequest()) {
            jsonResponse([
                'success' => true,
                'message' => 'Leave request submitted',
                'id' => $leaveId,
            ], 201);
            return;
        }

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Leave request submitted successfully'];
        header('Location: /leave');
        exit;
    }

    /**
     * Approve a leave request
     * PUT /leave/{id}/approve
     */
    public function approve(string $id): void
    {
        $user = currentUser();

        if (!$user) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Unauthorized'], 401);
            }
            header('Location: /auth/login');
            exit;
        }

        if (!hasRole('officer')) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Forbidden'], 403);
            }
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        $leaveId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];
        $approverId = (int)$user['id'];

        // Check if request exists and belongs to this brigade
        $request = $this->leaveModel->findById($leaveId);

        if (!$request || !$this->leaveModel->belongsToBrigade($leaveId, $brigadeId)) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Leave request not found'], 404);
            }
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Leave request not found']);
            return;
        }

        if ($request['status'] !== 'pending') {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Leave request is not pending'], 400);
            }
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Leave request is not pending'];
            header('Location: /leave/pending');
            exit;
        }

        // Approve the request
        $success = $this->leaveModel->approve($leaveId, $approverId);

        if ($success) {
            // Log the action
            $this->logAction('leave.approve', 'leave_request', $leaveId, [
                'member_id' => $request['member_id'],
                'training_date' => $request['training_date'],
            ]);

            // Notify the member (TODO: implement push notification)
            $this->notifyMember($request['member_id'], 'approved', $request['training_date']);
        }

        if ($this->isApiRequest()) {
            jsonResponse([
                'success' => $success,
                'message' => $success ? 'Leave request approved' : 'Failed to approve leave request',
            ]);
            return;
        }

        $_SESSION['flash'] = [
            'type' => $success ? 'success' : 'error',
            'message' => $success ? 'Leave request approved' : 'Failed to approve leave request',
        ];
        header('Location: /leave/pending');
        exit;
    }

    /**
     * Deny a leave request
     * PUT /leave/{id}/deny
     */
    public function deny(string $id): void
    {
        $user = currentUser();

        if (!$user) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Unauthorized'], 401);
            }
            header('Location: /auth/login');
            exit;
        }

        if (!hasRole('officer')) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Forbidden'], 403);
            }
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        $leaveId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];
        $denierId = (int)$user['id'];

        // Check if request exists and belongs to this brigade
        $request = $this->leaveModel->findById($leaveId);

        if (!$request || !$this->leaveModel->belongsToBrigade($leaveId, $brigadeId)) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Leave request not found'], 404);
            }
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Leave request not found']);
            return;
        }

        if ($request['status'] !== 'pending') {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Leave request is not pending'], 400);
            }
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Leave request is not pending'];
            header('Location: /leave/pending');
            exit;
        }

        // Deny the request
        $success = $this->leaveModel->deny($leaveId, $denierId);

        if ($success) {
            // Log the action
            $this->logAction('leave.deny', 'leave_request', $leaveId, [
                'member_id' => $request['member_id'],
                'training_date' => $request['training_date'],
            ]);

            // Notify the member (TODO: implement push notification)
            $this->notifyMember($request['member_id'], 'denied', $request['training_date']);
        }

        if ($this->isApiRequest()) {
            jsonResponse([
                'success' => $success,
                'message' => $success ? 'Leave request denied' : 'Failed to deny leave request',
            ]);
            return;
        }

        $_SESSION['flash'] = [
            'type' => $success ? 'success' : 'error',
            'message' => $success ? 'Leave request denied' : 'Failed to deny leave request',
        ];
        header('Location: /leave/pending');
        exit;
    }

    /**
     * Cancel/delete a leave request
     * DELETE /leave/{id}
     */
    public function destroy(string $id): void
    {
        $user = currentUser();

        if (!$user) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Unauthorized'], 401);
            }
            header('Location: /auth/login');
            exit;
        }

        // Verify CSRF token for non-API requests
        if (!$this->isApiRequest() && !verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            http_response_code(403);
            echo 'Invalid CSRF token';
            return;
        }

        $leaveId = (int)$id;
        $memberId = (int)$user['id'];

        // Check if request exists and belongs to this user
        $request = $this->leaveModel->findById($leaveId);

        if (!$request) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Leave request not found'], 404);
            }
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Leave request not found']);
            return;
        }

        // Users can only cancel their own pending requests
        // Officers/Admins can cancel any pending request in their brigade
        $canCancel = false;

        if ($request['member_id'] === $memberId && $request['status'] === 'pending') {
            $canCancel = true;
        } elseif (hasRole('officer') && $this->leaveModel->belongsToBrigade($leaveId, (int)$user['brigade_id'])) {
            $canCancel = true;
        }

        if (!$canCancel) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Cannot cancel this leave request'], 403);
            }
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        if ($request['status'] !== 'pending') {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Only pending requests can be cancelled'], 400);
            }
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Only pending requests can be cancelled'];
            header('Location: /leave');
            exit;
        }

        // Cancel the request
        $success = $this->leaveModel->cancel($leaveId);

        if ($success) {
            // Log the action
            $this->logAction('leave.cancel', 'leave_request', $leaveId, [
                'member_id' => $request['member_id'],
                'training_date' => $request['training_date'],
                'cancelled_by' => $memberId,
            ]);
        }

        if ($this->isApiRequest()) {
            jsonResponse([
                'success' => $success,
                'message' => $success ? 'Leave request cancelled' : 'Failed to cancel leave request',
            ]);
            return;
        }

        $_SESSION['flash'] = [
            'type' => $success ? 'success' : 'error',
            'message' => $success ? 'Leave request cancelled' : 'Failed to cancel leave request',
        ];
        header('Location: /leave');
        exit;
    }

    /**
     * Get request data from POST or JSON body
     */
    private function getRequestData(): array
    {
        if ($this->isApiRequest()) {
            $json = file_get_contents('php://input');
            $data = json_decode($json, true) ?? [];
        } else {
            $data = $_POST;
        }

        return [
            'training_date' => trim($data['training_date'] ?? ''),
            'reason' => isset($data['reason']) ? trim($data['reason']) : null,
        ];
    }

    /**
     * Check if this is an API request
     */
    private function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_starts_with($uri, '/api/') || str_contains($accept, 'application/json');
    }

    /**
     * Notify officers about a new leave request
     */
    private function notifyOfficers(array $member, string $trainingDate): void
    {
        global $db, $config;

        $brigadeId = $member['brigade_id'];
        $formattedDate = date('l, j F', strtotime($trainingDate));

        // Get officers to notify
        $stmt = $db->prepare("
            SELECT id, email, name FROM members
            WHERE brigade_id = ? AND status = 'active' AND role IN ('officer', 'admin', 'superadmin')
        ");
        $stmt->execute([$brigadeId]);
        $officers = $stmt->fetchAll();

        if (empty($officers)) {
            return;
        }

        // Send push notifications
        require_once __DIR__ . '/../Services/PushService.php';
        $pushService = new PushService($config['push'] ?? [], $db);

        if ($pushService->isEnabled()) {
            foreach ($officers as $officer) {
                $pushService->send(
                    $officer['id'],
                    'New Leave Request',
                    "{$member['name']} has requested leave for {$formattedDate}",
                    [
                        'type' => 'leave_request',
                        'url' => ($config['base_path'] ?? '') . '/leave/pending'
                    ]
                );
            }
        }

        // Send email notifications
        require_once __DIR__ . '/../Services/EmailService.php';
        $emailService = new EmailService($config);
        $emailService->sendLeaveNotification($officers, [
            'member_name' => $member['name'],
            'training_date' => $trainingDate,
            'reason' => ''
        ]);
    }

    /**
     * Notify a member about their leave request decision
     */
    private function notifyMember(int $memberId, string $decision, string $trainingDate): void
    {
        global $db, $config;

        $formattedDate = date('l, j F', strtotime($trainingDate));
        $decisionText = $decision === 'approved' ? 'approved' : 'denied';

        // Get member details
        $stmt = $db->prepare("SELECT id, email, name, brigade_id FROM members WHERE id = ?");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch();

        if (!$member) {
            return;
        }

        // Get who made the decision
        $decidedBy = currentUser();
        $decidedByName = $decidedBy ? $decidedBy['name'] : 'An officer';

        // Send push notification
        require_once __DIR__ . '/../Services/PushService.php';
        $pushService = new PushService($config['push'] ?? [], $db);

        if ($pushService->isEnabled()) {
            $pushService->send(
                $memberId,
                "Leave Request {$decisionText}",
                "Your leave request for {$formattedDate} has been {$decisionText}",
                [
                    'type' => 'leave_decision',
                    'decision' => $decision,
                    'url' => ($config['base_path'] ?? '') . '/leave'
                ]
            );
        }

        // Send email notification
        require_once __DIR__ . '/../Services/EmailService.php';
        $emailService = new EmailService($config);
        $emailService->sendLeaveDecision($member, [
            'training_date' => $trainingDate,
            'decided_by_name' => $decidedByName,
            'reason' => ''
        ], $decision);
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
            // Log error but don't fail the request
            error_log('Failed to log action: ' . $e->getMessage());
        }
    }
}
