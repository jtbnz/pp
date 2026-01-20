<?php
declare(strict_types=1);

/**
 * Member API Controller
 *
 * Handles all API endpoints for member management.
 * All responses are in JSON format.
 */
class MemberApiController
{
    private PDO $db;
    private Member $memberModel;

    public function __construct()
    {
        global $db;
        $this->db = $db;
        $this->memberModel = new Member($db);
    }

    /**
     * GET /api/members
     * List all members (admin only)
     */
    public function index(): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        if (!hasRole('admin')) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        // Parse query parameters for filters
        $filters = [
            'role' => $_GET['role'] ?? null,
            'status' => $_GET['status'] ?? 'active',
            'search' => $_GET['search'] ?? null,
            'order_by' => $_GET['order_by'] ?? 'name',
            'order_dir' => $_GET['order_dir'] ?? 'ASC'
        ];

        // Pagination
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
        $filters['limit'] = $perPage;
        $filters['offset'] = ($page - 1) * $perPage;

        $members = $this->memberModel->findByBrigade($user['brigade_id'], $filters);
        $totalCount = $this->memberModel->countByBrigade($user['brigade_id'], $filters);

        // Remove sensitive data from response
        $members = array_map([$this, 'sanitizeMemberData'], $members);

        jsonResponse([
            'data' => $members,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalCount,
                'total_pages' => (int)ceil($totalCount / $perPage)
            ]
        ]);
    }

    /**
     * GET /api/members/{id}
     * Get a single member
     */
    public function show(string $id): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $memberId = (int)$id;
        $member = $this->memberModel->findById($memberId);

        if (!$member) {
            jsonResponse(['error' => 'Member not found'], 404);
            return;
        }

        // Users can view their own profile, admins can view all in their brigade
        $canView = ($member['id'] === $user['id'])
            || (hasRole('admin') && $member['brigade_id'] === $user['brigade_id']);

        if (!$canView) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        // Get service periods for admin
        $servicePeriods = [];
        $serviceInfo = null;

        if (hasRole('admin') || $member['id'] === $user['id']) {
            $servicePeriods = $this->memberModel->getServicePeriods($memberId);
            $serviceInfo = $this->memberModel->calculateServiceForHonors($memberId);
        }

        $responseData = $this->sanitizeMemberData($member);
        $responseData['service_periods'] = $servicePeriods;
        $responseData['service_info'] = $serviceInfo;

        jsonResponse(['data' => $responseData]);
    }

    /**
     * POST /api/members
     * Create a new member (admin only)
     */
    public function store(): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        if (!hasRole('admin')) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        // Parse JSON body
        $input = $this->getJsonInput();

        $email = strtolower(trim($input['email'] ?? ''));
        $name = trim($input['name'] ?? '');
        $role = $input['role'] ?? 'firefighter';
        $phone = trim($input['phone'] ?? '') ?: null;
        $rank = $input['rank'] ?? null;
        $rankDate = $input['rank_date'] ?? null;

        $errors = [];

        // Validate email
        if (empty($email)) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        } else {
            $existing = $this->memberModel->findByEmail($email, $user['brigade_id']);
            if ($existing) {
                $errors['email'] = 'A member with this email already exists';
            }
        }

        // Validate name
        if (empty($name)) {
            $errors['name'] = 'Name is required';
        } elseif (strlen($name) > 100) {
            $errors['name'] = 'Name must be 100 characters or less';
        }

        // Validate role
        if (!in_array($role, Member::getValidRoles(), true)) {
            $errors['role'] = 'Invalid role';
        }

        // Validate rank if provided
        if ($rank && !in_array($rank, Member::getValidRanks(), true)) {
            $errors['rank'] = 'Invalid rank';
        }

        if (!empty($errors)) {
            jsonResponse(['error' => 'Validation failed', 'errors' => $errors], 422);
            return;
        }

        try {
            // Generate access token
            $accessToken = bin2hex(random_bytes(32));
            $accessExpires = (new DateTimeImmutable('+5 years'))->format('Y-m-d H:i:s');

            $memberId = $this->memberModel->create([
                'brigade_id' => $user['brigade_id'],
                'email' => $email,
                'name' => $name,
                'phone' => $phone,
                'role' => $role,
                'rank' => $rank,
                'rank_date' => $rankDate,
                'access_token' => $accessToken,
                'access_expires' => $accessExpires
            ]);

            // Create invite token - store expiry in UTC
            $inviteToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $inviteToken);
            $inviteExpires = gmdate('Y-m-d H:i:s', time() + (7 * 86400));

            $stmt = $this->db->prepare('
                INSERT INTO invite_tokens (brigade_id, email, token_hash, role, expires_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $user['brigade_id'],
                $email,
                $tokenHash,
                $role,
                $inviteExpires,
                $user['id']
            ]);

            $this->logAudit('member_created', 'member', $memberId, [
                'email' => $email,
                'role' => $role,
                'created_by' => $user['id']
            ]);

            $newMember = $this->memberModel->findById($memberId);

            jsonResponse([
                'data' => $this->sanitizeMemberData($newMember),
                'invite_token' => $inviteToken,
                'message' => 'Member created successfully'
            ], 201);

        } catch (Exception $e) {
            jsonResponse(['error' => 'Failed to create member: ' . $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/members/{id}
     * Update a member (admin only)
     */
    public function update(string $id): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        if (!hasRole('admin')) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $memberId = (int)$id;
        $member = $this->memberModel->findById($memberId);

        if (!$member) {
            jsonResponse(['error' => 'Member not found'], 404);
            return;
        }

        if ($member['brigade_id'] !== $user['brigade_id']) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $input = $this->getJsonInput();
        $errors = [];

        // Validate name if provided
        if (isset($input['name'])) {
            $name = trim($input['name']);
            if (empty($name)) {
                $errors['name'] = 'Name cannot be empty';
            } elseif (strlen($name) > 100) {
                $errors['name'] = 'Name must be 100 characters or less';
            }
        }

        // Validate role if provided
        if (isset($input['role']) && !in_array($input['role'], Member::getValidRoles(), true)) {
            $errors['role'] = 'Invalid role';
        }

        // Validate rank if provided
        if (isset($input['rank']) && $input['rank'] && !in_array($input['rank'], Member::getValidRanks(), true)) {
            $errors['rank'] = 'Invalid rank';
        }

        // Validate status if provided
        if (isset($input['status']) && !in_array($input['status'], ['active', 'inactive'], true)) {
            $errors['status'] = 'Invalid status';
        }

        if (!empty($errors)) {
            jsonResponse(['error' => 'Validation failed', 'errors' => $errors], 422);
            return;
        }

        $updateData = [];
        $allowedFields = ['name', 'phone', 'role', 'rank', 'rank_date', 'status'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $input)) {
                $updateData[$field] = is_string($input[$field]) ? trim($input[$field]) : $input[$field];
                if ($updateData[$field] === '') {
                    $updateData[$field] = null;
                }
            }
        }

        if (empty($updateData)) {
            jsonResponse(['error' => 'No valid fields to update'], 400);
            return;
        }

        try {
            $this->memberModel->update($memberId, $updateData);

            $this->logAudit('member_updated', 'member', $memberId, [
                'updated_fields' => array_keys($updateData),
                'updated_by' => $user['id']
            ]);

            $updatedMember = $this->memberModel->findById($memberId);

            jsonResponse([
                'data' => $this->sanitizeMemberData($updatedMember),
                'message' => 'Member updated successfully'
            ]);

        } catch (Exception $e) {
            jsonResponse(['error' => 'Failed to update member: ' . $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/members/{id}
     * Deactivate a member (admin only)
     */
    public function destroy(string $id): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        if (!hasRole('admin')) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $memberId = (int)$id;
        $member = $this->memberModel->findById($memberId);

        if (!$member) {
            jsonResponse(['error' => 'Member not found'], 404);
            return;
        }

        if ($member['brigade_id'] !== $user['brigade_id']) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        // Prevent self-deactivation
        if ($memberId === $user['id']) {
            jsonResponse(['error' => 'Cannot deactivate yourself'], 400);
            return;
        }

        try {
            $this->memberModel->deactivate($memberId);

            $this->logAudit('member_deactivated', 'member', $memberId, [
                'deactivated_by' => $user['id']
            ]);

            jsonResponse(['message' => 'Member deactivated successfully']);

        } catch (Exception $e) {
            jsonResponse(['error' => 'Failed to deactivate member'], 500);
        }
    }

    /**
     * GET /api/members/{id}/service-periods
     * Get service periods for a member
     */
    public function getServicePeriods(string $id): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $memberId = (int)$id;
        $member = $this->memberModel->findById($memberId);

        if (!$member) {
            jsonResponse(['error' => 'Member not found'], 404);
            return;
        }

        // Check access: own profile or admin in same brigade
        $canView = ($member['id'] === $user['id'])
            || (hasRole('admin') && $member['brigade_id'] === $user['brigade_id']);

        if (!$canView) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $servicePeriods = $this->memberModel->getServicePeriods($memberId);
        $serviceInfo = $this->memberModel->calculateServiceForHonors($memberId);

        jsonResponse([
            'data' => $servicePeriods,
            'service_info' => $serviceInfo
        ]);
    }

    /**
     * POST /api/members/{id}/service-periods
     * Add a service period (admin only)
     */
    public function addServicePeriod(string $id): void
    {
        $user = currentUser();
        if (!$user || !hasRole('admin')) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $memberId = (int)$id;
        $member = $this->memberModel->findById($memberId);

        if (!$member || $member['brigade_id'] !== $user['brigade_id']) {
            jsonResponse(['error' => 'Member not found'], 404);
            return;
        }

        $input = $this->getJsonInput();

        $startDate = $input['start_date'] ?? '';
        $endDate = $input['end_date'] ?? null;
        $notes = trim($input['notes'] ?? '');

        $errors = [];

        if (empty($startDate)) {
            $errors['start_date'] = 'Start date is required';
        } elseif (!$this->isValidDate($startDate)) {
            $errors['start_date'] = 'Invalid date format (YYYY-MM-DD)';
        }

        if ($endDate && !$this->isValidDate($endDate)) {
            $errors['end_date'] = 'Invalid date format (YYYY-MM-DD)';
        }

        if ($startDate && $endDate && $startDate > $endDate) {
            $errors['end_date'] = 'End date must be after start date';
        }

        if (!empty($errors)) {
            jsonResponse(['error' => 'Validation failed', 'errors' => $errors], 422);
            return;
        }

        try {
            $periodId = $this->memberModel->addServicePeriod($memberId, [
                'start_date' => $startDate,
                'end_date' => $endDate ?: null,
                'notes' => $notes ?: null
            ]);

            $this->logAudit('service_period_added', 'service_period', $periodId, [
                'member_id' => $memberId,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);

            $period = $this->memberModel->getServicePeriod($periodId);
            $serviceInfo = $this->memberModel->calculateServiceForHonors($memberId);

            jsonResponse([
                'data' => $period,
                'service_info' => $serviceInfo,
                'message' => 'Service period added successfully'
            ], 201);

        } catch (InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Failed to add service period'], 500);
        }
    }

    /**
     * PUT /api/members/{id}/service-periods/{periodId}
     * Update a service period (admin only)
     */
    public function updateServicePeriod(string $id, string $periodId): void
    {
        $user = currentUser();
        if (!$user || !hasRole('admin')) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $memberId = (int)$id;
        $periodIdInt = (int)$periodId;

        $member = $this->memberModel->findById($memberId);
        $period = $this->memberModel->getServicePeriod($periodIdInt);

        if (!$member || !$period || $member['brigade_id'] !== $user['brigade_id']) {
            jsonResponse(['error' => 'Not found'], 404);
            return;
        }

        if ($period['member_id'] !== $memberId) {
            jsonResponse(['error' => 'Period does not belong to this member'], 400);
            return;
        }

        $input = $this->getJsonInput();
        $errors = [];
        $updateData = [];

        if (array_key_exists('start_date', $input)) {
            if (!$this->isValidDate($input['start_date'])) {
                $errors['start_date'] = 'Invalid date format (YYYY-MM-DD)';
            } else {
                $updateData['start_date'] = $input['start_date'];
            }
        }

        if (array_key_exists('end_date', $input)) {
            if ($input['end_date'] !== null && !$this->isValidDate($input['end_date'])) {
                $errors['end_date'] = 'Invalid date format (YYYY-MM-DD)';
            } else {
                $updateData['end_date'] = $input['end_date'];
            }
        }

        if (array_key_exists('notes', $input)) {
            $updateData['notes'] = trim($input['notes']) ?: null;
        }

        if (!empty($errors)) {
            jsonResponse(['error' => 'Validation failed', 'errors' => $errors], 422);
            return;
        }

        if (empty($updateData)) {
            jsonResponse(['error' => 'No valid fields to update'], 400);
            return;
        }

        try {
            $this->memberModel->updateServicePeriod($periodIdInt, $updateData);

            $this->logAudit('service_period_updated', 'service_period', $periodIdInt, [
                'member_id' => $memberId,
                'updated_fields' => array_keys($updateData)
            ]);

            $updatedPeriod = $this->memberModel->getServicePeriod($periodIdInt);
            $serviceInfo = $this->memberModel->calculateServiceForHonors($memberId);

            jsonResponse([
                'data' => $updatedPeriod,
                'service_info' => $serviceInfo,
                'message' => 'Service period updated successfully'
            ]);

        } catch (InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Failed to update service period'], 500);
        }
    }

    /**
     * DELETE /api/members/{id}/service-periods/{periodId}
     * Delete a service period (admin only)
     */
    public function deleteServicePeriod(string $id, string $periodId): void
    {
        $user = currentUser();
        if (!$user || !hasRole('admin')) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $memberId = (int)$id;
        $periodIdInt = (int)$periodId;

        $member = $this->memberModel->findById($memberId);
        $period = $this->memberModel->getServicePeriod($periodIdInt);

        if (!$member || !$period || $member['brigade_id'] !== $user['brigade_id']) {
            jsonResponse(['error' => 'Not found'], 404);
            return;
        }

        if ($period['member_id'] !== $memberId) {
            jsonResponse(['error' => 'Period does not belong to this member'], 400);
            return;
        }

        try {
            $this->memberModel->deleteServicePeriod($periodIdInt);

            $this->logAudit('service_period_deleted', 'service_period', $periodIdInt, [
                'member_id' => $memberId
            ]);

            $serviceInfo = $this->memberModel->calculateServiceForHonors($memberId);

            jsonResponse([
                'service_info' => $serviceInfo,
                'message' => 'Service period deleted successfully'
            ]);

        } catch (Exception $e) {
            jsonResponse(['error' => 'Failed to delete service period'], 500);
        }
    }

    /**
     * Remove sensitive data from member response
     */
    private function sanitizeMemberData(array $member): array
    {
        unset(
            $member['access_token'],
            $member['pin_hash'],
            $member['push_subscription']
        );

        // Add display names
        if (isset($member['role'])) {
            $member['role_display'] = Member::getRoleDisplayName($member['role']);
        }

        if (isset($member['rank']) && $member['rank']) {
            $member['rank_display'] = Member::getRankDisplayName($member['rank']);
        }

        return $member;
    }

    /**
     * Get JSON input from request body
     */
    private function getJsonInput(): array
    {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        return is_array($input) ? $input : [];
    }

    /**
     * Validate date format (YYYY-MM-DD)
     */
    private function isValidDate(string $date): bool
    {
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * Log an audit action
     */
    private function logAudit(string $action, string $entityType, int $entityId, array $details = []): void
    {
        $user = currentUser();

        $stmt = $this->db->prepare('
            INSERT INTO audit_log (brigade_id, member_id, action, entity_type, entity_id, details, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            $user['brigade_id'] ?? null,
            $user['id'] ?? null,
            $action,
            $entityType,
            $entityId,
            json_encode($details),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }

    // =========================================================================
    // USER PREFERENCES ENDPOINTS (Issue #23)
    // =========================================================================

    /**
     * PUT /api/members/{id}/preferences
     * Update user preferences (own profile only)
     */
    public function updatePreferences(string $id): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $memberId = (int)$id;

        // Users can only update their own preferences
        if ($memberId !== $user['id']) {
            jsonResponse(['error' => 'You can only update your own preferences'], 403);
            return;
        }

        $input = $this->getJsonInput();

        // Get current preferences
        $stmt = $this->db->prepare('SELECT preferences FROM members WHERE id = ?');
        $stmt->execute([$memberId]);
        $result = $stmt->fetch();

        $currentPrefs = [];
        if ($result && !empty($result['preferences'])) {
            $currentPrefs = json_decode($result['preferences'], true) ?: [];
        }

        // Validate and merge new preferences
        $allowedKeys = ['color_blind_mode'];
        foreach ($input as $key => $value) {
            if (in_array($key, $allowedKeys, true)) {
                // Sanitize value based on key
                if ($key === 'color_blind_mode') {
                    $currentPrefs[$key] = (bool)$value;
                }
            }
        }

        // Save updated preferences
        try {
            $stmt = $this->db->prepare('UPDATE members SET preferences = ?, updated_at = datetime("now", "localtime") WHERE id = ?');
            $stmt->execute([json_encode($currentPrefs), $memberId]);

            // Update session if this affects display
            if (isset($input['color_blind_mode'])) {
                $_SESSION['color_blind_mode'] = (bool)$input['color_blind_mode'];
            }

            jsonResponse([
                'message' => 'Preferences updated successfully',
                'preferences' => $currentPrefs
            ]);

        } catch (Exception $e) {
            jsonResponse(['error' => 'Failed to update preferences'], 500);
        }
    }

    // =========================================================================
    // ATTENDANCE ENDPOINTS
    // =========================================================================

    /**
     * GET /api/members/{id}/attendance
     * Get attendance statistics for a member
     */
    public function attendance(string $id): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $memberId = (int)$id;
        $member = $this->memberModel->findById($memberId);

        if (!$member) {
            jsonResponse(['error' => 'Member not found'], 404);
            return;
        }

        // Users can view their own attendance, admins/officers can view all in their brigade
        $canView = ($member['id'] === $user['id'])
            || ((hasRole('admin') || hasRole('officer')) && $member['brigade_id'] === $user['brigade_id']);

        if (!$canView) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        global $config;
        require_once __DIR__ . '/../../Services/AttendanceService.php';
        $attendanceService = new AttendanceService($this->db, $config);

        $stats = $attendanceService->getMemberStats($memberId);
        $syncStatus = $attendanceService->getSyncStatus((int)$member['brigade_id']);

        jsonResponse([
            'success' => true,
            'stats' => $stats,
            'sync' => $syncStatus ? [
                'last_sync' => $syncStatus['last_sync_at'],
                'status' => $syncStatus['status'],
            ] : null,
        ]);
    }

    /**
     * GET /api/members/{id}/attendance/recent
     * Get recent attendance events for a member
     */
    public function attendanceRecent(string $id): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $memberId = (int)$id;
        $member = $this->memberModel->findById($memberId);

        if (!$member) {
            jsonResponse(['error' => 'Member not found'], 404);
            return;
        }

        // Users can view their own attendance, admins/officers can view all in their brigade
        $canView = ($member['id'] === $user['id'])
            || ((hasRole('admin') || hasRole('officer')) && $member['brigade_id'] === $user['brigade_id']);

        if (!$canView) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));

        global $config;
        require_once __DIR__ . '/../../Services/AttendanceService.php';
        $attendanceService = new AttendanceService($this->db, $config);

        $events = $attendanceService->getRecentEvents($memberId, $limit);

        // Format events for display
        $formattedEvents = array_map(function ($event) {
            return [
                'date' => $event['event_date'],
                'date_formatted' => date('j M Y', strtotime($event['event_date'])),
                'day_name' => date('l', strtotime($event['event_date'])),
                'type' => $event['event_type'],
                'type_label' => AttendanceService::formatEventType($event['event_type']),
                'status' => $event['status'],
                'status_label' => AttendanceService::formatStatus($event['status']),
                'position' => $event['position'],
                'position_label' => AttendanceService::formatPosition($event['position']),
                'truck' => $event['truck'],
            ];
        }, $events);

        jsonResponse([
            'success' => true,
            'events' => $formattedEvents,
        ]);
    }

    /**
     * POST /api/attendance/sync
     * Trigger attendance sync from DLB (admin only)
     */
    public function syncAttendance(): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        if (!hasRole('admin')) {
            jsonResponse(['error' => 'Forbidden - admin only'], 403);
            return;
        }

        $input = $this->getJsonInput();
        $fullSync = (bool)($input['full_sync'] ?? false);

        global $config;
        require_once __DIR__ . '/../../Services/AttendanceService.php';
        $attendanceService = new AttendanceService($this->db, $config);

        $result = $attendanceService->syncFromDlb((int)$user['brigade_id'], $fullSync);

        if ($result['success']) {
            $this->logAudit('attendance.sync', 'brigade', (int)$user['brigade_id'], [
                'full_sync' => $fullSync,
                'created' => $result['created'],
                'updated' => $result['updated'],
            ]);
        }

        jsonResponse($result);
    }
}
