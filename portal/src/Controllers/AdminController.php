<?php
declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Models\Member;
use Portal\Models\Event;
use Portal\Models\Notice;
use Portal\Models\AuditLog;
use Portal\Models\Settings;
use Portal\Models\Poll;
use Portal\Services\EmailService;
use Portal\Services\AuthService;
use PDO;
use Exception;
use DateTime;

/**
 * Admin Controller
 *
 * Handles all admin dashboard and management functionality.
 * All methods require admin role (enforced by router middleware).
 */
class AdminController
{
    private PDO $db;
    private Member $memberModel;
    private Event $eventModel;
    private Notice $noticeModel;
    private AuditLog $auditLog;
    private Settings $settings;

    public function __construct()
    {
        global $db;
        $this->db = $db;
        $this->memberModel = new Member($db);
        $this->eventModel = new Event();
        $this->noticeModel = new Notice($db);
        $this->auditLog = new AuditLog($db);
        $this->settings = new Settings($db);
    }

    /**
     * Main admin dashboard with stats
     */
    public function dashboard(): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];

        // Get stats
        $stats = $this->getDashboardStats($brigadeId);

        // Get recent activity
        $recentActivity = $this->auditLog->getRecent($brigadeId, 10);

        // Get quick action counts
        $pendingLeaveCount = $this->getPendingLeaveCount($brigadeId);
        $upcomingEventsCount = $this->getUpcomingEventsCount($brigadeId);

        render('pages/admin/dashboard', [
            'pageTitle' => 'Admin Dashboard',
            'stats' => $stats,
            'recentActivity' => $recentActivity,
            'pendingLeaveCount' => $pendingLeaveCount,
            'upcomingEventsCount' => $upcomingEventsCount
        ]);
    }

    /**
     * Member list management
     */
    public function members(): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];

        // Get filter parameters
        $filters = [
            'role' => $_GET['role'] ?? null,
            'status' => $_GET['status'] ?? 'active',
            'search' => $_GET['search'] ?? null,
            'order_by' => $_GET['order_by'] ?? 'name',
            'order_dir' => $_GET['order_dir'] ?? 'ASC'
        ];

        // Remove null filters
        $filters = array_filter($filters, fn($v) => $v !== null);

        $members = $this->memberModel->findByBrigade($brigadeId, $filters);
        $totalCount = $this->memberModel->countByBrigade($brigadeId, $filters);

        render('pages/admin/members/index', [
            'pageTitle' => 'Manage Members',
            'members' => $members,
            'totalCount' => $totalCount,
            'filters' => $filters,
            'roles' => Member::getValidRoles(),
            'ranks' => Member::getValidRanks()
        ]);
    }

    /**
     * Show invite member form
     */
    public function inviteForm(): void
    {
        render('pages/admin/members/invite', [
            'pageTitle' => 'Invite Member',
            'roles' => Member::getValidRoles(),
            'ranks' => Member::getValidRanks()
        ]);
    }

    /**
     * Send member invite
     */
    public function invite(): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];

        // Validate CSRF
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            $this->redirectWithError(url('/admin/members/invite'), 'Invalid request. Please try again.');
            return;
        }

        // Validate input
        $email = trim($_POST['email'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '') ?: null;
        $role = $_POST['role'] ?? 'firefighter';
        $rank = $_POST['rank'] ?? null;

        $errors = [];

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Valid email address is required';
        }

        if (empty($name)) {
            $errors['name'] = 'Name is required';
        }

        if (!in_array($role, Member::getValidRoles(), true)) {
            $errors['role'] = 'Invalid role selected';
        }

        if ($rank !== null && !in_array($rank, Member::getValidRanks(), true)) {
            $errors['rank'] = 'Invalid rank selected';
        }

        // Check if email already exists in brigade
        if (empty($errors['email'])) {
            $existing = $this->memberModel->findByEmail($email, $brigadeId);
            if ($existing) {
                $errors['email'] = 'A member with this email already exists';
            }
        }

        if (!empty($errors)) {
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: ' . url('/admin/members/invite'));
            exit;
        }

        // Generate access token
        $accessToken = bin2hex(random_bytes(32));
        $accessExpires = date('Y-m-d H:i:s', strtotime('+5 years'));

        try {
            // Create member with pending status
            $memberId = $this->memberModel->create([
                'brigade_id' => $brigadeId,
                'email' => $email,
                'name' => $name,
                'phone' => $phone,
                'role' => $role,
                'rank' => $rank,
                'access_token' => $accessToken,
                'access_expires' => $accessExpires
            ]);

            // Log the action
            $this->auditLog->log($brigadeId, $user['id'], 'member.invite', [
                'member_id' => $memberId,
                'email' => $email,
                'name' => $name,
                'role' => $role
            ]);

            // Create invite token - store expiry in UTC
            $inviteToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $inviteToken);
            $inviteExpires = gmdate('Y-m-d H:i:s', time() + (7 * 86400));

            $stmtToken = $this->db->prepare("
                INSERT INTO invite_tokens (brigade_id, email, token_hash, role, expires_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmtToken->execute([
                $brigadeId,
                $email,
                $tokenHash,
                $role,
                $inviteExpires,
                $user['id']
            ]);

            // Send invite email with magic link
            global $config;
            $emailService = new EmailService($config);

            // Get brigade name
            $stmtBrigade = $this->db->prepare('SELECT name FROM brigades WHERE id = ?');
            $stmtBrigade->execute([$brigadeId]);
            $brigadeName = $stmtBrigade->fetchColumn() ?: 'Puke Fire Brigade';

            $emailSent = $emailService->sendInvite($email, $inviteToken, $brigadeName);

            if ($emailSent) {
                $_SESSION['flash_message'] = "Member invited successfully. An invitation email has been sent to {$email}.";
            } else {
                $_SESSION['flash_message'] = "Member created. Email could not be sent. Manual invite link: " . url('/auth/verify/' . $inviteToken);
            }
            $_SESSION['flash_type'] = 'success';
            header('Location: ' . url('/admin/members'));
            exit;

        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Failed to invite member. Please try again.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . url('/admin/members/invite'));
            exit;
        }
    }

    /**
     * Show edit member form
     */
    public function editMember(string $id): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];
        $memberId = (int)$id;

        $member = $this->memberModel->findById($memberId);

        if (!$member || $member['brigade_id'] !== $brigadeId) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Member not found']);
            return;
        }

        // Get service periods for this member
        $servicePeriods = $this->memberModel->getServicePeriods($memberId);
        $serviceInfo = $this->memberModel->calculateServiceForHonors($memberId);

        render('pages/admin/members/edit', [
            'pageTitle' => 'Edit Member',
            'member' => $member,
            'servicePeriods' => $servicePeriods,
            'serviceInfo' => $serviceInfo,
            'roles' => Member::getValidRoles(),
            'ranks' => Member::getValidRanks()
        ]);
    }

    /**
     * Send a login link to a member
     */
    public function sendLoginLink(string $id): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];
        $memberId = (int)$id;

        // Validate CSRF
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            $this->redirectWithError(url("/admin/members/{$id}"), 'Invalid request. Please try again.');
            return;
        }

        $member = $this->memberModel->findById($memberId);

        if (!$member || $member['brigade_id'] !== $brigadeId) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Member not found']);
            return;
        }

        try {
            // Create magic link token
            global $config;
            $authService = new AuthService($this->db, $config);

            $token = $authService->createInviteToken(
                $brigadeId,
                $member['email'],
                $member['role']
            );

            // Send magic link email
            $emailService = new EmailService($config);

            $basePath = $config['base_path'] ?? '';
            $magicLinkUrl = $config['app_url'] . $basePath . '/auth/verify/' . $token;

            $emailSent = $emailService->sendMagicLink(
                $member['email'],
                $member['name'],
                $magicLinkUrl
            );

            // Log the action
            $this->auditLog->log($brigadeId, $user['id'], 'member.send_login_link', [
                'member_id' => $memberId,
                'email' => $member['email'],
                'sent_by' => $user['id']
            ]);

            // Store the magic link for display on the admin page (for testing)
            $_SESSION['last_magic_link'] = $magicLinkUrl;
            $_SESSION['last_magic_link_member_id'] = $memberId;

            if ($emailSent) {
                $_SESSION['flash_message'] = "Login link sent to {$member['email']}.";
                $_SESSION['flash_type'] = 'success';
            } else {
                // Show the link if email failed
                $_SESSION['flash_message'] = "Email could not be sent. Direct login link: {$magicLinkUrl}";
                $_SESSION['flash_type'] = 'warning';
            }

        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Failed to send login link. Please try again.';
            $_SESSION['flash_type'] = 'error';
        }

        header('Location: ' . url("/admin/members/{$id}"));
        exit;
    }

    /**
     * Update member
     */
    public function updateMember(string $id): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];
        $memberId = (int)$id;

        // Validate CSRF
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            $this->redirectWithError(url("/admin/members/{$id}"), 'Invalid request. Please try again.');
            return;
        }

        $member = $this->memberModel->findById($memberId);

        if (!$member || $member['brigade_id'] !== $brigadeId) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Member not found']);
            return;
        }

        // Validate input
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '') ?: null;
        $role = $_POST['role'] ?? $member['role'];
        $rank = $_POST['rank'] ?? null;
        $status = $_POST['status'] ?? $member['status'];

        $errors = [];

        if (empty($name)) {
            $errors['name'] = 'Name is required';
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Valid email address is required';
        }

        // Check if email already exists for another member in the brigade
        if (empty($errors['email']) && strtolower($email) !== strtolower($member['email'])) {
            $existing = $this->memberModel->findByEmail($email, $brigadeId);
            if ($existing && $existing['id'] !== $memberId) {
                $errors['email'] = 'A member with this email already exists';
            }
        }

        if (!in_array($role, Member::getValidRoles(), true)) {
            $errors['role'] = 'Invalid role selected';
        }

        if ($rank !== null && $rank !== '' && !in_array($rank, Member::getValidRanks(), true)) {
            $errors['rank'] = 'Invalid rank selected';
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $errors['status'] = 'Invalid status selected';
        }

        // Prevent self-demotion for last admin
        if ($memberId === $user['id'] && $role !== 'admin' && $role !== 'superadmin') {
            $adminCount = $this->memberModel->countByBrigade($brigadeId, ['role' => 'admin']);
            $superadminCount = $this->memberModel->countByBrigade($brigadeId, ['role' => 'superadmin']);
            if (($adminCount + $superadminCount) <= 1) {
                $errors['role'] = 'Cannot demote yourself as you are the only admin';
            }
        }

        if (!empty($errors)) {
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: ' . url("/admin/members/{$id}"));
            exit;
        }

        try {
            // Track changes for audit log
            $changes = [];
            if ($name !== $member['name']) $changes['name'] = ['from' => $member['name'], 'to' => $name];
            if (strtolower($email) !== strtolower($member['email'])) $changes['email'] = ['from' => $member['email'], 'to' => $email];
            if ($role !== $member['role']) $changes['role'] = ['from' => $member['role'], 'to' => $role];
            if ($rank !== $member['rank']) $changes['rank'] = ['from' => $member['rank'], 'to' => $rank];
            if ($status !== $member['status']) $changes['status'] = ['from' => $member['status'], 'to' => $status];

            // Update member
            $this->memberModel->update($memberId, [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'role' => $role,
                'rank' => $rank !== '' ? $rank : null,
                'status' => $status
            ]);

            // Log the action
            if (!empty($changes)) {
                $this->auditLog->log($brigadeId, $user['id'], 'member.update', [
                    'member_id' => $memberId,
                    'name' => $name,
                    'changes' => $changes
                ]);
            }

            $_SESSION['flash_message'] = 'Member updated successfully.';
            $_SESSION['flash_type'] = 'success';
            header('Location: ' . url('/admin/members'));
            exit;

        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Failed to update member. Please try again.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . url("/admin/members/{$id}"));
            exit;
        }
    }

    /**
     * Event management list
     */
    public function events(): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];

        // Get date range for events
        $from = $_GET['from'] ?? date('Y-m-d');
        $to = $_GET['to'] ?? date('Y-m-d', strtotime('+3 months'));

        $events = $this->eventModel->findByDateRange($brigadeId, $from, $to);

        render('pages/admin/events/index', [
            'pageTitle' => 'Manage Events',
            'events' => $events,
            'from' => $from,
            'to' => $to
        ]);
    }

    /**
     * Show create event form
     */
    public function createEventForm(): void
    {
        render('pages/admin/events/create', [
            'pageTitle' => 'Create Event'
        ]);
    }

    /**
     * Create new event
     */
    public function createEvent(): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];

        // Validate CSRF
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            $this->redirectWithError(url('/admin/events/create'), 'Invalid request. Please try again.');
            return;
        }

        // Validate input
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '') ?: null;
        $location = trim($_POST['location'] ?? '') ?: null;
        $startDate = $_POST['start_date'] ?? '';
        $startTime = $_POST['start_time'] ?? '00:00';
        $endDate = $_POST['end_date'] ?? '';
        $endTime = $_POST['end_time'] ?? '';
        $allDay = isset($_POST['all_day']) && $_POST['all_day'] === '1';
        $isTraining = isset($_POST['is_training']) && $_POST['is_training'] === '1';

        $errors = [];

        if (empty($title)) {
            $errors['title'] = 'Title is required';
        }

        if (empty($startDate)) {
            $errors['start_date'] = 'Start date is required';
        }

        if (!empty($errors)) {
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: ' . url('/admin/events/create'));
            exit;
        }

        // Build datetime strings
        $startDateTime = $startDate . ' ' . ($allDay ? '00:00:00' : $startTime . ':00');
        $endDateTime = null;
        if (!empty($endDate)) {
            $endDateTime = $endDate . ' ' . ($allDay ? '23:59:59' : ($endTime ?: '23:59') . ':00');
        }

        try {
            $eventId = $this->eventModel->create([
                'brigade_id' => $brigadeId,
                'title' => $title,
                'description' => $description,
                'location' => $location,
                'start_time' => $startDateTime,
                'end_time' => $endDateTime,
                'all_day' => $allDay ? 1 : 0,
                'is_training' => $isTraining ? 1 : 0,
                'is_visible' => 1,
                'created_by' => $user['id']
            ]);

            // Log the action
            $this->auditLog->log($brigadeId, $user['id'], 'event.create', [
                'event_id' => $eventId,
                'title' => $title
            ]);

            $_SESSION['flash_message'] = 'Event created successfully.';
            $_SESSION['flash_type'] = 'success';
            header('Location: ' . url('/admin/events'));
            exit;

        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Failed to create event. Please try again.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . url('/admin/events/create'));
            exit;
        }
    }

    /**
     * Notice management list
     */
    public function notices(): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];

        // Get filter parameters
        $filters = [
            'type' => $_GET['type'] ?? null,
            'search' => $_GET['search'] ?? null
        ];

        // Remove null filters
        $filters = array_filter($filters, fn($v) => $v !== null);

        $notices = $this->noticeModel->findAll($brigadeId, $filters);
        $totalCount = $this->noticeModel->count($brigadeId, $filters);

        render('pages/admin/notices/index', [
            'pageTitle' => 'Manage Notices',
            'notices' => $notices,
            'totalCount' => $totalCount,
            'filters' => $filters
        ]);
    }

    /**
     * Show create notice form
     */
    public function createNoticeForm(): void
    {
        render('pages/admin/notices/create', [
            'pageTitle' => 'Create Notice'
        ]);
    }

    /**
     * Create new notice
     */
    public function createNotice(): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];

        // Validate CSRF
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            $this->redirectWithError(url('/admin/notices/create'), 'Invalid request. Please try again.');
            return;
        }

        // Validate using model
        $data = [
            'brigade_id' => $brigadeId,
            'title' => trim($_POST['title'] ?? ''),
            'content' => trim($_POST['content'] ?? '') ?: null,
            'type' => $_POST['type'] ?? 'standard',
            'display_from' => !empty($_POST['display_from']) ? $_POST['display_from'] : null,
            'display_to' => !empty($_POST['display_to']) ? $_POST['display_to'] : null,
            'author_id' => $user['id']
        ];

        $errors = $this->noticeModel->validate($data);

        if (!empty($errors)) {
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: ' . url('/admin/notices/create'));
            exit;
        }

        try {
            $noticeId = $this->noticeModel->create($data);

            // Log the action
            $this->auditLog->log($brigadeId, $user['id'], 'notice.create', [
                'notice_id' => $noticeId,
                'title' => $data['title'],
                'type' => $data['type']
            ]);

            $_SESSION['flash_message'] = 'Notice created successfully.';
            $_SESSION['flash_type'] = 'success';
            header('Location: ' . url('/admin/notices'));
            exit;

        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Failed to create notice. Please try again.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . url('/admin/notices/create'));
            exit;
        }
    }

    /**
     * Leave requests management
     */
    public function leaveRequests(): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];

        // Get filter parameters
        $status = $_GET['status'] ?? null;
        $from = $_GET['from'] ?? date('Y-m-d', strtotime('-1 month'));
        $to = $_GET['to'] ?? date('Y-m-d', strtotime('+3 months'));

        // Query leave requests
        $sql = "
            SELECT lr.*, m.name as member_name,
                   d.name as decided_by_name
            FROM leave_requests lr
            JOIN members m ON lr.member_id = m.id
            LEFT JOIN members d ON lr.decided_by = d.id
            WHERE m.brigade_id = ?
            AND lr.training_date >= ?
            AND lr.training_date <= ?
        ";

        $params = [$brigadeId, $from, $to];

        if ($status !== null) {
            $sql .= " AND lr.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY lr.training_date ASC, lr.requested_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $leaveRequests = $stmt->fetchAll();

        render('pages/admin/leave/index', [
            'pageTitle' => 'Leave Requests',
            'leaveRequests' => $leaveRequests,
            'from' => $from,
            'to' => $to,
            'status' => $status
        ]);
    }

    /**
     * Brigade settings form
     */
    public function settings(): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];

        // Get all settings
        $allSettings = $this->settings->getAll($brigadeId);

        // Merge with defaults for any missing settings
        $defaults = Settings::getDefaults();
        $settings = array_merge($defaults, $allSettings);

        // Get brigade info
        $stmt = $this->db->prepare('SELECT * FROM brigades WHERE id = ?');
        $stmt->execute([$brigadeId]);
        $brigade = $stmt->fetch();

        render('pages/admin/settings', [
            'pageTitle' => 'Brigade Settings',
            'settings' => $settings,
            'brigade' => $brigade
        ]);
    }

    /**
     * Update brigade settings
     */
    public function updateSettings(): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];

        // Validate CSRF
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            $this->redirectWithError(url('/admin/settings'), 'Invalid request. Please try again.');
            return;
        }

        try {
            // Process settings from form
            $settingsToUpdate = [];

            // Training settings
            if (isset($_POST['training_day'])) {
                $settingsToUpdate['training.day'] = $_POST['training_day'];
            }
            if (isset($_POST['training_time'])) {
                $settingsToUpdate['training.time'] = $_POST['training_time'];
            }
            if (isset($_POST['training_duration'])) {
                $settingsToUpdate['training.duration_hours'] = (int)$_POST['training_duration'];
            }
            if (isset($_POST['training_location'])) {
                $settingsToUpdate['training.location'] = $_POST['training_location'];
            }
            $settingsToUpdate['training.move_on_holiday'] = isset($_POST['training_move_on_holiday']);

            // Notification settings
            $settingsToUpdate['notifications.leave_request'] = isset($_POST['notify_leave_request']);
            $settingsToUpdate['notifications.leave_approved'] = isset($_POST['notify_leave_approved']);
            $settingsToUpdate['notifications.new_notice'] = isset($_POST['notify_new_notice']);
            $settingsToUpdate['notifications.urgent_notice'] = isset($_POST['notify_urgent_notice']);
            $settingsToUpdate['notifications.training_reminder'] = isset($_POST['notify_training_reminder']);

            if (isset($_POST['training_reminder_hours'])) {
                $settingsToUpdate['notifications.training_reminder_hours'] = (int)$_POST['training_reminder_hours'];
            }

            // Leave settings
            if (isset($_POST['max_advance_trainings'])) {
                $settingsToUpdate['leave.max_advance_trainings'] = (int)$_POST['max_advance_trainings'];
            }
            $settingsToUpdate['leave.require_approval'] = isset($_POST['leave_require_approval']);
            $settingsToUpdate['leave.auto_approve_officers'] = isset($_POST['leave_auto_approve_officers']);

            // Display settings
            $settingsToUpdate['display.show_ranks'] = isset($_POST['display_show_ranks']);
            if (isset($_POST['calendar_start_day'])) {
                $settingsToUpdate['display.calendar_start_day'] = (int)$_POST['calendar_start_day'];
            }

            // Calendar settings
            $settingsToUpdate['calendar.show_holidays'] = isset($_POST['calendar_show_holidays']) ? '1' : '0';
            if (isset($_POST['calendar_holiday_region'])) {
                $settingsToUpdate['calendar.holiday_region'] = $_POST['calendar_holiday_region'];
            }

            // DLB Integration settings
            $settingsToUpdate['dlb.enabled'] = isset($_POST['dlb_enabled']);
            $settingsToUpdate['dlb.auto_sync'] = isset($_POST['dlb_auto_sync']);

            // Save all settings
            $this->settings->setMultiple($brigadeId, $settingsToUpdate);

            // Log the action
            $this->auditLog->log($brigadeId, $user['id'], 'settings.update', [
                'updated_keys' => array_keys($settingsToUpdate)
            ]);

            $_SESSION['flash_message'] = 'Settings updated successfully.';
            $_SESSION['flash_type'] = 'success';

        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Failed to update settings. Please try again.';
            $_SESSION['flash_type'] = 'error';
        }

        header('Location: ' . url('/admin/settings'));
        exit;
    }

    // =========================================================================
    // POLLS MANAGEMENT
    // =========================================================================

    /**
     * List all polls
     * GET /admin/polls
     */
    public function polls(): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];

        // Get filter
        $status = $_GET['status'] ?? null;
        $filters = $status ? ['status' => $status] : [];

        $pollModel = new Poll($this->db);

        $polls = $pollModel->findAll($brigadeId, $filters);
        $totalCount = $pollModel->count($brigadeId, $filters);

        render('pages/admin/polls/index', [
            'pageTitle' => 'Manage Polls',
            'polls' => $polls,
            'totalCount' => $totalCount,
            'status' => $status
        ]);
    }

    /**
     * Show create poll form
     * GET /admin/polls/create
     */
    public function createPollForm(): void
    {
        render('pages/admin/polls/create', [
            'pageTitle' => 'Create Poll'
        ]);
    }

    /**
     * Create a new poll
     * POST /admin/polls
     */
    public function createPoll(): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];

        // Validate CSRF
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            $this->redirectWithError(url('/admin/polls/create'), 'Invalid request. Please try again.');
            return;
        }

        $pollModel = new Poll($this->db);

        // Build data
        $data = [
            'brigade_id' => $brigadeId,
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? '') ?: null,
            'type' => $_POST['type'] ?? 'single',
            'closes_at' => !empty($_POST['closes_at']) ? $_POST['closes_at'] : null,
            'created_by' => $user['id'],
            'options' => $_POST['options'] ?? []
        ];

        // Validate
        $errors = $pollModel->validate($data);

        if (!empty($errors)) {
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: ' . url('/admin/polls/create'));
            exit;
        }

        try {
            $pollId = $pollModel->create($data);

            // Log the action
            $this->auditLog->log($brigadeId, $user['id'], 'poll.create', [
                'poll_id' => $pollId,
                'title' => $data['title'],
                'type' => $data['type']
            ]);

            $_SESSION['flash_message'] = 'Poll created successfully.';
            $_SESSION['flash_type'] = 'success';
            header('Location: ' . url('/admin/polls'));
            exit;

        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Failed to create poll. Please try again.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . url('/admin/polls/create'));
            exit;
        }
    }

    /**
     * Show edit poll form
     * GET /admin/polls/{id}
     */
    public function editPoll(string $id): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];
        $pollId = (int)$id;

        $pollModel = new Poll($this->db);

        $poll = $pollModel->findById($pollId);

        if (!$poll || !$pollModel->belongsToBrigade($pollId, $brigadeId)) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Poll not found']);
            return;
        }

        render('pages/admin/polls/edit', [
            'pageTitle' => 'Edit Poll',
            'poll' => $poll
        ]);
    }

    /**
     * Update a poll
     * PUT /admin/polls/{id}
     */
    public function updatePoll(string $id): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];
        $pollId = (int)$id;

        // Validate CSRF
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            $this->redirectWithError(url("/admin/polls/{$id}"), 'Invalid request. Please try again.');
            return;
        }

        $pollModel = new Poll($this->db);

        $poll = $pollModel->findById($pollId);

        if (!$poll || !$pollModel->belongsToBrigade($pollId, $brigadeId)) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Poll not found']);
            return;
        }

        // Build data
        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? '') ?: null,
            'type' => $_POST['type'] ?? 'single',
            'closes_at' => !empty($_POST['closes_at']) ? $_POST['closes_at'] : null,
            'options' => $_POST['options'] ?? []
        ];

        // Validate
        $errors = $pollModel->validate($data);

        if (!empty($errors)) {
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_data'] = $_POST;
            header('Location: ' . url("/admin/polls/{$id}"));
            exit;
        }

        try {
            $pollModel->update($pollId, $data);

            // Update options if poll hasn't received votes
            if ($poll['total_votes'] === 0) {
                $pollModel->updateOptions($pollId, $data['options']);
            }

            // Log the action
            $this->auditLog->log($brigadeId, $user['id'], 'poll.update', [
                'poll_id' => $pollId,
                'title' => $data['title']
            ]);

            $_SESSION['flash_message'] = 'Poll updated successfully.';
            $_SESSION['flash_type'] = 'success';
            header('Location: ' . url('/admin/polls'));
            exit;

        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Failed to update poll. Please try again.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . url("/admin/polls/{$id}"));
            exit;
        }
    }

    /**
     * Close a poll
     * POST /admin/polls/{id}/close
     */
    public function closePoll(string $id): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];
        $pollId = (int)$id;

        // Validate CSRF
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            $this->redirectWithError(url('/admin/polls'), 'Invalid request. Please try again.');
            return;
        }

        $pollModel = new Poll($this->db);

        $poll = $pollModel->findById($pollId);

        if (!$poll || !$pollModel->belongsToBrigade($pollId, $brigadeId)) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Poll not found']);
            return;
        }

        try {
            $pollModel->close($pollId);

            // Log the action
            $this->auditLog->log($brigadeId, $user['id'], 'poll.close', [
                'poll_id' => $pollId,
                'title' => $poll['title']
            ]);

            $_SESSION['flash_message'] = 'Poll closed successfully.';
            $_SESSION['flash_type'] = 'success';

        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Failed to close poll. Please try again.';
            $_SESSION['flash_type'] = 'error';
        }

        header('Location: ' . url('/admin/polls'));
        exit;
    }

    /**
     * Delete a poll
     * DELETE /admin/polls/{id}
     */
    public function deletePoll(string $id): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];
        $pollId = (int)$id;

        // Validate CSRF
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            $this->redirectWithError(url('/admin/polls'), 'Invalid request. Please try again.');
            return;
        }

        $pollModel = new Poll($this->db);

        $poll = $pollModel->findById($pollId);

        if (!$poll || !$pollModel->belongsToBrigade($pollId, $brigadeId)) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Poll not found']);
            return;
        }

        try {
            $pollModel->delete($pollId);

            // Log the action
            $this->auditLog->log($brigadeId, $user['id'], 'poll.delete', [
                'poll_id' => $pollId,
                'title' => $poll['title']
            ]);

            $_SESSION['flash_message'] = 'Poll deleted successfully.';
            $_SESSION['flash_type'] = 'success';

        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Failed to delete poll. Please try again.';
            $_SESSION['flash_type'] = 'error';
        }

        header('Location: ' . url('/admin/polls'));
        exit;
    }

    /**
     * Get dashboard statistics
     */
    private function getDashboardStats(int $brigadeId): array
    {
        // Active members count
        $activeMembers = $this->memberModel->countByBrigade($brigadeId, ['status' => 'active']);

        // Pending leave requests
        $pendingLeave = $this->getPendingLeaveCount($brigadeId);

        // Upcoming events in next 30 days
        $upcomingEvents = $this->getUpcomingEventsCount($brigadeId);

        // Active notices
        $activeNotices = $this->noticeModel->count($brigadeId, ['active_only' => true]);

        // Active polls
        $pollModel = new Poll($this->db);
        $activePolls = $pollModel->count($brigadeId, ['status' => 'active']);

        return [
            'active_members' => $activeMembers,
            'pending_leave' => $pendingLeave,
            'upcoming_events' => $upcomingEvents,
            'active_notices' => $activeNotices,
            'active_polls' => $activePolls
        ];
    }

    /**
     * Get count of pending leave requests
     */
    private function getPendingLeaveCount(int $brigadeId): int
    {
        $sql = "
            SELECT COUNT(*)
            FROM leave_requests lr
            JOIN members m ON lr.member_id = m.id
            WHERE m.brigade_id = ?
            AND lr.status = 'pending'
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$brigadeId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Get count of upcoming events in next 30 days
     */
    private function getUpcomingEventsCount(int $brigadeId): int
    {
        $sql = "
            SELECT COUNT(*)
            FROM events
            WHERE brigade_id = ?
            AND is_visible = 1
            AND DATE(start_time) >= DATE('now')
            AND DATE(start_time) <= DATE('now', '+30 days')
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$brigadeId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Helper to redirect with error message
     */
    private function redirectWithError(string $url, string $message): void
    {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = 'error';
        header("Location: {$url}");
        exit;
    }

    // =========================================================================
    // CALENDAR IMPORT
    // =========================================================================

    /**
     * Show calendar import form
     * GET /admin/events/import
     */
    public function importEventsForm(): void
    {
        render('pages/admin/events/import', [
            'pageTitle' => 'Import Calendar Events'
        ]);
    }

    /**
     * Preview CSV import
     * POST /admin/events/import/preview
     */
    public function previewImportEvents(): void
    {
        // Validate CSRF
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            $this->redirectWithError(url('/admin/events/import'), 'Invalid request. Please try again.');
            return;
        }

        // Check file upload
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File is too large (exceeds server limit)',
                UPLOAD_ERR_FORM_SIZE => 'File is too large',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
                UPLOAD_ERR_EXTENSION => 'File upload blocked by extension',
            ];
            $errorCode = $_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $_SESSION['form_errors'] = ['file' => $errorMessages[$errorCode] ?? 'Upload failed'];
            header('Location: ' . url('/admin/events/import'));
            exit;
        }

        // Check file size (max 1MB)
        if ($_FILES['csv_file']['size'] > 1024 * 1024) {
            $_SESSION['form_errors'] = ['file' => 'File is too large (max 1MB)'];
            header('Location: ' . url('/admin/events/import'));
            exit;
        }

        // Parse CSV
        $skipDuplicates = isset($_POST['skip_duplicates']);
        $defaultTraining = isset($_POST['default_training']);
        $result = $this->parseCsvFile($_FILES['csv_file']['tmp_name'], $defaultTraining);

        if ($result === false) {
            $_SESSION['form_errors'] = ['file' => 'Failed to parse CSV file. Please check the format.'];
            header('Location: ' . url('/admin/events/import'));
            exit;
        }

        // Check for duplicates if requested
        if ($skipDuplicates && !empty($result['valid'])) {
            $user = currentUser();
            $brigadeId = $user['brigade_id'];
            $result['valid'] = $this->filterDuplicateEvents($brigadeId, $result['valid']);
        }

        // Store preview data in session
        $_SESSION['import_preview'] = $result;
        $_SESSION['form_data'] = $_POST;

        header('Location: ' . url('/admin/events/import'));
        exit;
    }

    /**
     * Execute the import
     * POST /admin/events/import
     */
    public function importEvents(): void
    {
        $user = currentUser();
        $brigadeId = $user['brigade_id'];

        // Validate CSRF
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            $this->redirectWithError(url('/admin/events/import'), 'Invalid request. Please try again.');
            return;
        }

        // Check if confirmed
        if (!isset($_POST['confirmed']) || $_POST['confirmed'] !== '1') {
            $this->redirectWithError(url('/admin/events/import'), 'Import not confirmed.');
            return;
        }

        // Get import data
        $importData = json_decode($_POST['import_data'] ?? '[]', true);
        if (empty($importData)) {
            $this->redirectWithError(url('/admin/events/import'), 'No events to import.');
            return;
        }

        // Import events
        $created = 0;
        $skipped = [];

        foreach ($importData as $eventData) {
            try {
                // Build datetime
                $startDateTime = $eventData['date'];
                if (!empty($eventData['start_time'])) {
                    $startDateTime .= ' ' . $eventData['start_time'] . ':00';
                } else {
                    $startDateTime .= ' 00:00:00';
                }

                $endDateTime = null;
                if (!empty($eventData['end_time'])) {
                    $endDateTime = $eventData['date'] . ' ' . $eventData['end_time'] . ':00';
                }

                $this->eventModel->create([
                    'brigade_id' => $brigadeId,
                    'title' => $eventData['title'],
                    'description' => $eventData['description'] ?? null,
                    'location' => $eventData['location'] ?? null,
                    'start_time' => $startDateTime,
                    'end_time' => $endDateTime,
                    'all_day' => $eventData['all_day'] ?? 0,
                    'is_training' => $eventData['is_training'] ?? 0,
                    'is_visible' => 1,
                    'created_by' => $user['id']
                ]);

                $created++;
            } catch (Exception $e) {
                $skipped[] = $eventData['title'] . ' (' . $eventData['date'] . ')';
            }
        }

        // Log the action
        $this->auditLog->log($brigadeId, $user['id'], 'events.import', [
            'created' => $created,
            'skipped' => count($skipped)
        ]);

        // Store result
        $_SESSION['import_result'] = [
            'success' => $created > 0,
            'created' => $created,
            'skipped' => $skipped
        ];

        header('Location: ' . url('/admin/events/import'));
        exit;
    }

    /**
     * Parse a CSV file into event data
     *
     * @param string $filePath Path to CSV file
     * @param bool $defaultTraining Whether to mark events as training by default
     * @return array|false Array with 'valid' and 'errors' keys, or false on failure
     */
    private function parseCsvFile(string $filePath, bool $defaultTraining = false): array|false
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return false;
        }

        $valid = [];
        $errors = [];
        $headers = null;
        $rowNum = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;

            // Skip empty rows
            if (count($row) === 1 && empty($row[0])) {
                continue;
            }

            // First row is headers
            if ($headers === null) {
                $headers = array_map(function($h) {
                    return strtolower(trim(str_replace([' ', '-'], '_', $h)));
                }, $row);

                // Validate required headers
                if (!in_array('title', $headers) || !in_array('date', $headers)) {
                    fclose($handle);
                    return false;
                }
                continue;
            }

            // Map row to associative array
            $data = [];
            foreach ($headers as $i => $header) {
                $data[$header] = $row[$i] ?? '';
            }

            // Validate and normalize row
            $result = $this->validateEventRow($data, $rowNum, $defaultTraining);

            if ($result['valid']) {
                $valid[] = $result['data'];
            } else {
                $errors[] = ['row' => $rowNum, 'message' => $result['error']];
            }
        }

        fclose($handle);

        return ['valid' => $valid, 'errors' => $errors];
    }

    /**
     * Validate and normalize a single event row
     *
     * @param array $data Row data
     * @param int $rowNum Row number for error reporting
     * @param bool $defaultTraining Default training flag
     * @return array ['valid' => bool, 'data' => array|null, 'error' => string|null]
     */
    private function validateEventRow(array $data, int $rowNum, bool $defaultTraining): array
    {
        // Required: title
        $title = trim($data['title'] ?? '');
        if (empty($title)) {
            return ['valid' => false, 'data' => null, 'error' => 'Title is required'];
        }

        // Required: date
        $dateStr = trim($data['date'] ?? '');
        if (empty($dateStr)) {
            return ['valid' => false, 'data' => null, 'error' => 'Date is required'];
        }

        // Parse date (supports YYYY-MM-DD or DD/MM/YYYY)
        $date = $this->parseDate($dateStr);
        if ($date === null) {
            return ['valid' => false, 'data' => null, 'error' => "Invalid date format: {$dateStr}"];
        }

        // Optional: start_time
        $startTime = null;
        if (!empty($data['start_time'])) {
            $startTime = $this->parseTime($data['start_time']);
            if ($startTime === null) {
                return ['valid' => false, 'data' => null, 'error' => "Invalid start time: {$data['start_time']}"];
            }
        }

        // Optional: end_time
        $endTime = null;
        if (!empty($data['end_time'])) {
            $endTime = $this->parseTime($data['end_time']);
            if ($endTime === null) {
                return ['valid' => false, 'data' => null, 'error' => "Invalid end time: {$data['end_time']}"];
            }
        }

        // Optional: is_training
        $isTraining = $defaultTraining;
        if (isset($data['is_training']) && $data['is_training'] !== '') {
            $isTraining = $this->parseBoolean($data['is_training']);
        }

        // Optional: all_day
        $allDay = empty($startTime);
        if (isset($data['all_day']) && $data['all_day'] !== '') {
            $allDay = $this->parseBoolean($data['all_day']);
        }

        return [
            'valid' => true,
            'data' => [
                'title' => $title,
                'date' => $date,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'description' => trim($data['description'] ?? '') ?: null,
                'location' => trim($data['location'] ?? '') ?: null,
                'is_training' => $isTraining ? 1 : 0,
                'all_day' => $allDay ? 1 : 0,
            ],
            'error' => null
        ];
    }

    /**
     * Parse a date string into YYYY-MM-DD format
     *
     * @param string $dateStr Date string
     * @return string|null Normalized date or null on failure
     */
    private function parseDate(string $dateStr): ?string
    {
        // Try YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            $dt = DateTime::createFromFormat('Y-m-d', $dateStr);
            if ($dt && $dt->format('Y-m-d') === $dateStr) {
                return $dateStr;
            }
        }

        // Try DD/MM/YYYY
        if (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $dateStr)) {
            $dt = DateTime::createFromFormat('d/m/Y', $dateStr);
            if ($dt) {
                return $dt->format('Y-m-d');
            }
        }

        // Try natural language parsing
        $timestamp = strtotime($dateStr);
        if ($timestamp !== false && $timestamp > 0) {
            return date('Y-m-d', $timestamp);
        }

        return null;
    }

    /**
     * Parse a time string into HH:MM format
     *
     * @param string $timeStr Time string
     * @return string|null Normalized time or null on failure
     */
    private function parseTime(string $timeStr): ?string
    {
        $timeStr = trim($timeStr);

        // Try HH:MM (24hr)
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $timeStr, $m)) {
            $hour = (int)$m[1];
            $min = (int)$m[2];
            if ($hour >= 0 && $hour <= 23 && $min >= 0 && $min <= 59) {
                return sprintf('%02d:%02d', $hour, $min);
            }
        }

        // Try HH:MM AM/PM
        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', $timeStr, $m)) {
            $hour = (int)$m[1];
            $min = (int)$m[2];
            $ampm = strtoupper($m[3]);

            if ($hour >= 1 && $hour <= 12 && $min >= 0 && $min <= 59) {
                if ($ampm === 'PM' && $hour < 12) {
                    $hour += 12;
                } elseif ($ampm === 'AM' && $hour === 12) {
                    $hour = 0;
                }
                return sprintf('%02d:%02d', $hour, $min);
            }
        }

        // Try natural parsing
        $timestamp = strtotime($timeStr);
        if ($timestamp !== false) {
            return date('H:i', $timestamp);
        }

        return null;
    }

    /**
     * Parse a boolean value from string
     *
     * @param mixed $value Value to parse
     * @return bool
     */
    private function parseBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $str = strtolower(trim((string)$value));
        return in_array($str, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    /**
     * Filter out events that already exist in the database
     *
     * @param int $brigadeId Brigade ID
     * @param array $events Events to check
     * @return array Filtered events
     */
    private function filterDuplicateEvents(int $brigadeId, array $events): array
    {
        $filtered = [];

        foreach ($events as $event) {
            // Check if event with same title and date already exists
            $stmt = $this->db->prepare('
                SELECT id FROM events
                WHERE brigade_id = ?
                  AND title = ?
                  AND DATE(start_time) = ?
            ');
            $stmt->execute([$brigadeId, $event['title'], $event['date']]);

            if (!$stmt->fetch()) {
                $filtered[] = $event;
            }
        }

        return $filtered;
    }

    // =========================================================================
    // VIEW AS FUNCTIONALITY (SUPERADMIN ONLY)
    // =========================================================================

    /**
     * Start viewing as a different role
     * POST /admin/view-as
     */
    public function startViewAs(): void
    {
        $user = currentUser();

        // Only superadmins can use view-as
        if (!$user || $user['role'] !== 'superadmin') {
            http_response_code(403);
            render('pages/errors/403', ['message' => 'Only super admins can use this feature']);
            return;
        }

        // Validate CSRF
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            $this->redirectWithError(url('/admin'), 'Invalid request. Please try again.');
            return;
        }

        $role = $_POST['role'] ?? '';

        if (!startViewAs($role)) {
            $_SESSION['flash_message'] = 'Invalid role selected.';
            $_SESSION['flash_type'] = 'error';
        } else {
            // Log the action
            $this->auditLog->log($user['brigade_id'], $user['id'], 'view_as.start', [
                'role' => $role
            ]);

            $_SESSION['flash_message'] = "Now viewing as {$role}. This is read-only mode.";
            $_SESSION['flash_type'] = 'info';
        }

        // Redirect to home page to see the new view
        header('Location: ' . url('/'));
        exit;
    }

    /**
     * Stop viewing as a different role
     * POST /admin/view-as/stop
     */
    public function stopViewAs(): void
    {
        $user = currentUser();

        if (!$user) {
            header('Location: ' . url('/auth/login'));
            exit;
        }

        // Log the action before clearing
        if (isViewingAs()) {
            $this->auditLog->log($user['brigade_id'], $user['id'], 'view_as.stop', [
                'role' => getViewAsRole()
            ]);
        }

        clearViewAs();

        $_SESSION['flash_message'] = 'Returned to normal view.';
        $_SESSION['flash_type'] = 'success';

        header('Location: ' . url('/'));
        exit;
    }
}
