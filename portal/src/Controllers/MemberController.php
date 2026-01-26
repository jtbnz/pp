<?php
declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Models\Member;
use Portal\Services\EmailService;
use PDO;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

/**
 * Member Controller
 *
 * Handles member management for web views including:
 * - Member listing (admin only)
 * - Member profiles
 * - Member editing
 * - Service period management
 */
class MemberController
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
     * List all members (admin only)
     */
    public function index(): void
    {
        $user = currentUser();
        if (!$user) {
            header('Location: ' . url('/auth/login'));
            exit;
        }

        if (!hasRole('admin')) {
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        // Get filters from query params
        $filters = [
            'role' => $_GET['role'] ?? null,
            'status' => $_GET['status'] ?? 'active',
            'search' => $_GET['search'] ?? null,
            'order_by' => $_GET['order_by'] ?? 'name',
            'order_dir' => $_GET['order_dir'] ?? 'ASC'
        ];

        // Pagination
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $filters['limit'] = $perPage;
        $filters['offset'] = ($page - 1) * $perPage;

        $members = $this->memberModel->findByBrigade($user['brigade_id'], $filters);
        $totalCount = $this->memberModel->countByBrigade($user['brigade_id'], $filters);
        $totalPages = (int)ceil($totalCount / $perPage);

        render('pages/members/index', [
            'pageTitle' => 'Members',
            'members' => $members,
            'filters' => $filters,
            'pagination' => [
                'current' => $page,
                'total' => $totalPages,
                'count' => $totalCount
            ],
            'roles' => Member::getValidRoles(),
            'ranks' => Member::getValidRanks()
        ]);
    }

    /**
     * Show member profile
     */
    public function show(string $id): void
    {
        $user = currentUser();
        if (!$user) {
            header('Location: ' . url('/auth/login'));
            exit;
        }

        $memberId = (int)$id;
        $member = $this->memberModel->findById($memberId);

        if (!$member) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Member not found']);
            return;
        }

        // Check if user can view this member
        // Users can view their own profile, admins can view all in their brigade
        $canView = ($member['id'] === $user['id'])
            || (hasRole('admin') && $member['brigade_id'] === $user['brigade_id']);

        if (!$canView) {
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        // Get service periods
        $servicePeriods = $this->memberModel->getServicePeriods($memberId);
        $serviceInfo = $this->memberModel->calculateServiceForHonors($memberId);

        // Can the current user edit this member?
        $canEdit = hasRole('admin') && $member['brigade_id'] === $user['brigade_id'];

        render('pages/members/show', [
            'pageTitle' => $member['name'],
            'member' => $member,
            'servicePeriods' => $servicePeriods,
            'serviceInfo' => $serviceInfo,
            'canEdit' => $canEdit,
            'isOwnProfile' => $member['id'] === $user['id']
        ]);
    }

    /**
     * Show member edit form (admin only)
     */
    public function edit(string $id): void
    {
        $user = currentUser();
        if (!$user || !hasRole('admin')) {
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        $memberId = (int)$id;
        $member = $this->memberModel->findById($memberId);

        if (!$member) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Member not found']);
            return;
        }

        // Ensure member belongs to admin's brigade
        if ($member['brigade_id'] !== $user['brigade_id']) {
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        $servicePeriods = $this->memberModel->getServicePeriods($memberId);

        render('pages/members/edit', [
            'pageTitle' => 'Edit ' . $member['name'],
            'member' => $member,
            'servicePeriods' => $servicePeriods,
            'roles' => Member::getValidRoles(),
            'ranks' => Member::getValidRanks(),
            'errors' => $_SESSION['form_errors'] ?? [],
            'old' => $_SESSION['form_old'] ?? []
        ]);

        // Clear flash data
        unset($_SESSION['form_errors'], $_SESSION['form_old']);
    }

    /**
     * Store a new member via invite
     */
    public function store(): void
    {
        $user = currentUser();
        if (!$user || !hasRole('admin')) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        // Validate CSRF
        $csrfToken = $_POST['_csrf_token'] ?? '';
        if (!verifyCsrfToken($csrfToken)) {
            jsonResponse(['error' => 'Invalid CSRF token'], 403);
            return;
        }

        $email = strtolower(trim($_POST['email'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $role = $_POST['role'] ?? 'firefighter';
        $phone = trim($_POST['phone'] ?? '') ?: null;
        $rank = $_POST['rank'] ?? null;

        $errors = [];

        // Validate email
        if (empty($email)) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        } else {
            // Check if email already exists
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
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_old'] = $_POST;
            header('Location: ' . url('/admin/members/invite'));
            exit;
        }

        try {
            // Generate access token
            $accessToken = bin2hex(random_bytes(32));

            // Set access expiry to 5 years from now
            $accessExpires = (new DateTimeImmutable('+5 years'))->format('Y-m-d H:i:s');

            $memberId = $this->memberModel->create([
                'brigade_id' => $user['brigade_id'],
                'email' => $email,
                'name' => $name,
                'phone' => $phone,
                'role' => $role,
                'rank' => $rank,
                'access_token' => $accessToken,
                'access_expires' => $accessExpires
            ]);

            // Create invite token for magic link - store expiry in UTC
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

            // Log the action
            $this->logAudit('member_invited', 'member', $memberId, [
                'email' => $email,
                'role' => $role,
                'invited_by' => $user['id']
            ]);

            // Send invite email with magic link
            global $config;
            $emailService = new EmailService($config);

            // Get brigade name
            $stmtBrigade = $this->db->prepare('SELECT name FROM brigades WHERE id = ?');
            $stmtBrigade->execute([$user['brigade_id']]);
            $brigadeName = $stmtBrigade->fetchColumn() ?: 'Puke Fire Brigade';

            $emailSent = $emailService->sendInvite($email, $inviteToken, $brigadeName);

            if ($emailSent) {
                $_SESSION['flash_message'] = "Member invited successfully. An invitation email has been sent to {$email}.";
            } else {
                $_SESSION['flash_message'] = "Member invited successfully. Email could not be sent. Manual invite link: " . url('/auth/verify/' . $inviteToken);
            }
            $_SESSION['flash_type'] = 'success';

            header('Location: ' . url('/members/' . $memberId));
            exit;

        } catch (Exception $e) {
            $_SESSION['form_errors'] = ['general' => 'Failed to create member: ' . $e->getMessage()];
            $_SESSION['form_old'] = $_POST;
            header('Location: ' . url('/admin/members/invite'));
            exit;
        }
    }

    /**
     * Update a member (admin only)
     */
    public function update(string $id): void
    {
        $user = currentUser();
        if (!$user || !hasRole('admin')) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $memberId = (int)$id;
        $member = $this->memberModel->findById($memberId);

        if (!$member) {
            http_response_code(404);
            jsonResponse(['error' => 'Member not found'], 404);
            return;
        }

        if ($member['brigade_id'] !== $user['brigade_id']) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        // Validate CSRF
        $csrfToken = $_POST['_csrf_token'] ?? '';
        if (!verifyCsrfToken($csrfToken)) {
            jsonResponse(['error' => 'Invalid CSRF token'], 403);
            return;
        }

        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '') ?: null;
        $role = $_POST['role'] ?? null;
        $rank = $_POST['rank'] ?? null;
        $rankDate = $_POST['rank_date'] ?? null;
        $status = $_POST['status'] ?? null;

        $errors = [];

        if (empty($name)) {
            $errors['name'] = 'Name is required';
        }

        if ($role && !in_array($role, Member::getValidRoles(), true)) {
            $errors['role'] = 'Invalid role';
        }

        if ($rank && !in_array($rank, Member::getValidRanks(), true)) {
            $errors['rank'] = 'Invalid rank';
        }

        if (!empty($errors)) {
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_old'] = $_POST;
            header('Location: ' . url('/members/' . $memberId . '/edit'));
            exit;
        }

        $updateData = [
            'name' => $name,
            'phone' => $phone
        ];

        if ($role !== null) {
            $updateData['role'] = $role;
        }

        if ($rank !== null) {
            $updateData['rank'] = $rank ?: null;
        }

        if ($rankDate !== null) {
            $updateData['rank_date'] = $rankDate ?: null;
        }

        if ($status !== null && in_array($status, ['active', 'inactive'], true)) {
            $updateData['status'] = $status;
        }

        try {
            $this->memberModel->update($memberId, $updateData);

            $this->logAudit('member_updated', 'member', $memberId, [
                'updated_fields' => array_keys($updateData),
                'updated_by' => $user['id']
            ]);

            $_SESSION['flash_message'] = 'Member updated successfully';
            $_SESSION['flash_type'] = 'success';

            header('Location: ' . url('/members/' . $memberId));
            exit;

        } catch (Exception $e) {
            $_SESSION['form_errors'] = ['general' => 'Failed to update member: ' . $e->getMessage()];
            $_SESSION['form_old'] = $_POST;
            header('Location: ' . url('/members/' . $memberId . '/edit'));
            exit;
        }
    }

    /**
     * Deactivate a member (admin only)
     */
    public function destroy(string $id): void
    {
        $user = currentUser();
        if (!$user || !hasRole('admin')) {
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

        // Prevent deactivating yourself
        if ($memberId === $user['id']) {
            jsonResponse(['error' => 'Cannot deactivate yourself'], 400);
            return;
        }

        // Validate CSRF
        $csrfToken = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!verifyCsrfToken($csrfToken)) {
            jsonResponse(['error' => 'Invalid CSRF token'], 403);
            return;
        }

        try {
            $this->memberModel->deactivate($memberId);

            $this->logAudit('member_deactivated', 'member', $memberId, [
                'deactivated_by' => $user['id']
            ]);

            $_SESSION['flash_message'] = 'Member deactivated successfully';
            $_SESSION['flash_type'] = 'success';

            if ($this->isApiRequest()) {
                jsonResponse(['success' => true, 'message' => 'Member deactivated']);
            } else {
                header('Location: ' . url('/members'));
                exit;
            }

        } catch (Exception $e) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Failed to deactivate member'], 500);
            } else {
                $_SESSION['flash_message'] = 'Failed to deactivate member';
                $_SESSION['flash_type'] = 'error';
                header('Location: ' . url('/members/' . $memberId));
                exit;
            }
        }
    }

    /**
     * Manage service periods for a member
     */
    public function servicePeriods(string $id): void
    {
        $user = currentUser();
        if (!$user) {
            header('Location: ' . url('/auth/login'));
            exit;
        }

        $memberId = (int)$id;
        $member = $this->memberModel->findById($memberId);

        if (!$member) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Member not found']);
            return;
        }

        // Only admins can view/edit service periods
        if (!hasRole('admin') || $member['brigade_id'] !== $user['brigade_id']) {
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        $servicePeriods = $this->memberModel->getServicePeriods($memberId);
        $serviceInfo = $this->memberModel->calculateServiceForHonors($memberId);

        render('pages/members/service-periods', [
            'pageTitle' => 'Service History - ' . $member['name'],
            'member' => $member,
            'servicePeriods' => $servicePeriods,
            'serviceInfo' => $serviceInfo
        ]);
    }

    /**
     * Add a service period
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

        // Validate CSRF
        $csrfToken = $_POST['_csrf_token'] ?? '';
        if (!verifyCsrfToken($csrfToken)) {
            jsonResponse(['error' => 'Invalid CSRF token'], 403);
            return;
        }

        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? null;
        $notes = trim($_POST['notes'] ?? '');

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

            $_SESSION['flash_message'] = 'Service period added successfully';
            $_SESSION['flash_type'] = 'success';

            header('Location: ' . url('/members/' . $memberId));
            exit;

        } catch (InvalidArgumentException $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . url('/members/' . $memberId));
            exit;
        }
    }

    /**
     * Update a service period
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

        // Validate CSRF
        $csrfToken = $_POST['_csrf_token'] ?? '';
        if (!verifyCsrfToken($csrfToken)) {
            jsonResponse(['error' => 'Invalid CSRF token'], 403);
            return;
        }

        try {
            $updateData = [];

            if (isset($_POST['start_date'])) {
                $updateData['start_date'] = $_POST['start_date'];
            }

            if (array_key_exists('end_date', $_POST)) {
                $updateData['end_date'] = $_POST['end_date'] ?: null;
            }

            if (array_key_exists('notes', $_POST)) {
                $updateData['notes'] = trim($_POST['notes']) ?: null;
            }

            $this->memberModel->updateServicePeriod($periodIdInt, $updateData);

            $this->logAudit('service_period_updated', 'service_period', $periodIdInt, [
                'member_id' => $memberId,
                'updated_fields' => array_keys($updateData)
            ]);

            $_SESSION['flash_message'] = 'Service period updated successfully';
            $_SESSION['flash_type'] = 'success';

            header('Location: ' . url('/members/' . $memberId));
            exit;

        } catch (InvalidArgumentException $e) {
            $_SESSION['flash_message'] = $e->getMessage();
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . url('/members/' . $memberId));
            exit;
        }
    }

    /**
     * Delete a service period
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

        // Validate CSRF
        $csrfToken = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!verifyCsrfToken($csrfToken)) {
            jsonResponse(['error' => 'Invalid CSRF token'], 403);
            return;
        }

        try {
            $this->memberModel->deleteServicePeriod($periodIdInt);

            $this->logAudit('service_period_deleted', 'service_period', $periodIdInt, [
                'member_id' => $memberId
            ]);

            $_SESSION['flash_message'] = 'Service period deleted successfully';
            $_SESSION['flash_type'] = 'success';

            if ($this->isApiRequest()) {
                jsonResponse(['success' => true]);
            } else {
                header('Location: ' . url('/members/' . $memberId));
                exit;
            }

        } catch (Exception $e) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Failed to delete service period'], 500);
            } else {
                $_SESSION['flash_message'] = 'Failed to delete service period';
                $_SESSION['flash_type'] = 'error';
                header('Location: ' . url('/members/' . $memberId));
                exit;
            }
        }
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

    /**
     * Check if this is an API request
     */
    private function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        return str_starts_with($uri, '/api/') || str_contains($accept, 'application/json');
    }
}
