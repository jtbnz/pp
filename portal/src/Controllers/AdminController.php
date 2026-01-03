<?php
declare(strict_types=1);

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
            require_once __DIR__ . '/../Services/EmailService.php';
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
            require_once __DIR__ . '/../Services/AuthService.php';
            global $config;
            $authService = new AuthService($this->db, $config);

            $token = $authService->createInviteToken(
                $brigadeId,
                $member['email'],
                $member['role']
            );

            // Send magic link email
            require_once __DIR__ . '/../Services/EmailService.php';
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

        require_once __DIR__ . '/../Models/Poll.php';
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

        require_once __DIR__ . '/../Models/Poll.php';
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

        require_once __DIR__ . '/../Models/Poll.php';
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

        require_once __DIR__ . '/../Models/Poll.php';
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

        require_once __DIR__ . '/../Models/Poll.php';
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

        require_once __DIR__ . '/../Models/Poll.php';
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
}
