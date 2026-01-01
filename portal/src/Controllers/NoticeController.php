<?php
declare(strict_types=1);

/**
 * Notice Controller
 *
 * Handles web routes for the notice board system.
 */
class NoticeController
{
    private Notice $noticeModel;

    public function __construct()
    {
        require_once __DIR__ . '/../Models/Notice.php';
        require_once __DIR__ . '/../Helpers/Markdown.php';

        $this->noticeModel = new Notice();
    }

    /**
     * Display list of active notices
     * GET /notices
     */
    public function index(): void
    {
        $user = currentUser();

        if (!$user) {
            header('Location: ' . url('/auth/login'));
            exit;
        }

        $brigadeId = (int)$user['brigade_id'];

        // Get active notices for regular view, all for admins
        if (hasRole('admin')) {
            $filters = $_GET;
            $filters['limit'] = 50;
            $notices = $this->noticeModel->findAll($brigadeId, $filters);
            $totalNotices = $this->noticeModel->count($brigadeId, $filters);
        } else {
            $notices = $this->noticeModel->findActive($brigadeId);
            $totalNotices = count($notices);
        }

        // Render the view
        render('pages/notices/index', [
            'pageTitle' => 'Notices',
            'notices' => $notices,
            'totalNotices' => $totalNotices,
            'isAdmin' => hasRole('admin'),
        ]);
    }

    /**
     * Display a single notice
     * GET /notices/{id}
     */
    public function show(string $id): void
    {
        $user = currentUser();

        if (!$user) {
            header('Location: ' . url('/auth/login'));
            exit;
        }

        $noticeId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];

        $notice = $this->noticeModel->findById($noticeId);

        if (!$notice || $notice['brigade_id'] !== $brigadeId) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Notice not found']);
            return;
        }

        // Check if notice is active (unless admin)
        if (!hasRole('admin') && !$this->noticeModel->isActive($notice)) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Notice not found']);
            return;
        }

        // Calculate remaining time for timed notices
        $remainingSeconds = $this->noticeModel->getRemainingSeconds($notice);

        render('pages/notices/show', [
            'pageTitle' => $notice['title'],
            'notice' => $notice,
            'remainingSeconds' => $remainingSeconds,
            'isAdmin' => hasRole('admin'),
        ]);
    }

    /**
     * Display create notice form
     * GET /notices/create
     */
    public function create(): void
    {
        $user = currentUser();

        if (!$user || !hasRole('admin')) {
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        render('pages/notices/create', [
            'pageTitle' => 'Create Notice',
            'notice' => [
                'title' => '',
                'content' => '',
                'type' => 'standard',
                'display_from' => '',
                'display_to' => '',
            ],
            'errors' => [],
        ]);
    }

    /**
     * Store a new notice
     * POST /notices
     */
    public function store(): void
    {
        $user = currentUser();

        if (!$user || !hasRole('admin')) {
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        // Verify CSRF token
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            http_response_code(403);
            echo 'Invalid CSRF token';
            return;
        }

        $data = [
            'brigade_id' => (int)$user['brigade_id'],
            'title' => trim($_POST['title'] ?? ''),
            'content' => trim($_POST['content'] ?? ''),
            'type' => $_POST['type'] ?? 'standard',
            'display_from' => !empty($_POST['display_from']) ? $_POST['display_from'] : null,
            'display_to' => !empty($_POST['display_to']) ? $_POST['display_to'] : null,
            'author_id' => (int)$user['id'],
        ];

        // Validate
        $errors = $this->noticeModel->validate($data);

        if (!empty($errors)) {
            render('pages/notices/create', [
                'pageTitle' => 'Create Notice',
                'notice' => $data,
                'errors' => $errors,
            ]);
            return;
        }

        // Create the notice
        $noticeId = $this->noticeModel->create($data);

        // Log the action
        $this->logAction('notice.create', 'notice', $noticeId, $data);

        // Set flash message
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Notice created successfully'];

        // Redirect to notice detail
        header('Location: ' . url('/notices/' . $noticeId));
        exit;
    }

    /**
     * Display edit notice form
     * GET /notices/{id}/edit
     */
    public function edit(string $id): void
    {
        $user = currentUser();

        if (!$user || !hasRole('admin')) {
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        $noticeId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];

        $notice = $this->noticeModel->findById($noticeId);

        if (!$notice || $notice['brigade_id'] !== $brigadeId) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Notice not found']);
            return;
        }

        render('pages/notices/edit', [
            'pageTitle' => 'Edit Notice',
            'notice' => $notice,
            'errors' => [],
        ]);
    }

    /**
     * Update a notice
     * PUT /notices/{id}
     */
    public function update(string $id): void
    {
        $user = currentUser();

        if (!$user || !hasRole('admin')) {
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        // Verify CSRF token
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            http_response_code(403);
            echo 'Invalid CSRF token';
            return;
        }

        $noticeId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];

        $notice = $this->noticeModel->findById($noticeId);

        if (!$notice || $notice['brigade_id'] !== $brigadeId) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Notice not found']);
            return;
        }

        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'content' => trim($_POST['content'] ?? ''),
            'type' => $_POST['type'] ?? 'standard',
            'display_from' => !empty($_POST['display_from']) ? $_POST['display_from'] : null,
            'display_to' => !empty($_POST['display_to']) ? $_POST['display_to'] : null,
        ];

        // Validate
        $errors = $this->noticeModel->validate($data);

        if (!empty($errors)) {
            render('pages/notices/edit', [
                'pageTitle' => 'Edit Notice',
                'notice' => array_merge($notice, $data),
                'errors' => $errors,
            ]);
            return;
        }

        // Update the notice
        $this->noticeModel->update($noticeId, $data);

        // Log the action
        $this->logAction('notice.update', 'notice', $noticeId, $data);

        // Set flash message
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Notice updated successfully'];

        // Redirect to notice detail
        header('Location: ' . url('/notices/' . $noticeId));
        exit;
    }

    /**
     * Delete a notice
     * DELETE /notices/{id}
     */
    public function destroy(string $id): void
    {
        $user = currentUser();

        if (!$user || !hasRole('admin')) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Forbidden'], 403);
            }
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        // Verify CSRF token
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Invalid CSRF token'], 403);
            }
            http_response_code(403);
            echo 'Invalid CSRF token';
            return;
        }

        $noticeId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];

        $notice = $this->noticeModel->findById($noticeId);

        if (!$notice || $notice['brigade_id'] !== $brigadeId) {
            if ($this->isApiRequest()) {
                jsonResponse(['error' => 'Notice not found'], 404);
            }
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Notice not found']);
            return;
        }

        // Delete the notice
        $this->noticeModel->delete($noticeId);

        // Log the action
        $this->logAction('notice.delete', 'notice', $noticeId, ['title' => $notice['title']]);

        if ($this->isApiRequest()) {
            jsonResponse(['success' => true, 'message' => 'Notice deleted']);
            return;
        }

        // Set flash message
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Notice deleted successfully'];

        // Redirect to notices list
        header('Location: ' . url('/notices'));
        exit;
    }

    /**
     * Check if this is an API request
     */
    private function isApiRequest(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'application/json');
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
