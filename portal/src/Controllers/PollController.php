<?php
declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Models\Poll;
use Portal\Models\AuditLog;
use PDO;

/**
 * Poll Controller
 *
 * Handles member-facing poll views and voting.
 */
class PollController
{
    private PDO $db;
    private Poll $pollModel;
    private AuditLog $auditLog;

    public function __construct()
    {
        global $db;
        $this->db = $db;
        $this->pollModel = new Poll($db);
        $this->auditLog = new AuditLog($db);
    }

    /**
     * List all active polls
     * GET /polls
     */
    public function index(): void
    {
        $user = currentUser();
        if (!$user) {
            header('Location: ' . url('/auth/login'));
            exit;
        }

        $brigadeId = (int)$user['brigade_id'];

        // Get filter
        $status = $_GET['status'] ?? 'active';

        if ($status === 'all') {
            $polls = $this->pollModel->findAll($brigadeId);
        } elseif ($status === 'closed') {
            $polls = $this->pollModel->findAll($brigadeId, ['status' => 'closed']);
        } else {
            $polls = $this->pollModel->findActive($brigadeId);
        }

        // Add voting status for current user
        foreach ($polls as &$poll) {
            $poll['has_voted'] = $this->pollModel->hasVoted((int)$poll['id'], (int)$user['id']);
            $poll['user_votes'] = $this->pollModel->getMemberVotes((int)$poll['id'], (int)$user['id']);
        }

        render('pages/polls/index', [
            'pageTitle' => 'Polls',
            'polls' => $polls,
            'status' => $status,
            'canCreate' => true  // Any authenticated user can create polls
        ]);
    }

    /**
     * Show a single poll with results
     * GET /polls/{id}
     */
    public function show(string $id): void
    {
        $user = currentUser();
        if (!$user) {
            header('Location: ' . url('/auth/login'));
            exit;
        }

        $pollId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];

        $poll = $this->pollModel->findById($pollId);

        if (!$poll || !$this->pollModel->belongsToBrigade($pollId, $brigadeId)) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Poll not found']);
            return;
        }

        // Get user's votes
        $userVotes = $this->pollModel->getMemberVotes($pollId, (int)$user['id']);

        // Calculate percentages for results
        $totalVotes = max(1, $poll['total_votes']); // Avoid division by zero
        foreach ($poll['options'] as &$option) {
            $option['percentage'] = round(($option['vote_count'] / $totalVotes) * 100);
        }

        // User can edit if they created it or are an admin
        $canEdit = hasRole('admin') || (int)$poll['created_by'] === (int)$user['id'];

        render('pages/polls/show', [
            'pageTitle' => $poll['title'],
            'poll' => $poll,
            'userVotes' => $userVotes,
            'hasVoted' => !empty($userVotes),
            'canEdit' => $canEdit
        ]);
    }

    /**
     * Show create poll form
     * GET /polls/create
     */
    public function create(): void
    {
        $user = currentUser();
        if (!$user) {
            header('Location: ' . url('/auth/login'));
            exit;
        }

        render('pages/polls/create', [
            'pageTitle' => 'Create Poll',
            'formData' => [],
            'formErrors' => []
        ]);
    }

    /**
     * Store a new poll
     * POST /polls
     */
    public function store(): void
    {
        $user = currentUser();
        if (!$user) {
            header('Location: ' . url('/auth/login'));
            exit;
        }

        // Verify CSRF
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            $_SESSION['flash_message'] = 'Invalid request. Please try again.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . url('/polls/create'));
            exit;
        }

        $brigadeId = (int)$user['brigade_id'];
        $memberId = (int)$user['id'];

        $data = [
            'brigade_id' => $brigadeId,
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'type' => $_POST['type'] ?? 'single',
            'options' => $_POST['options'] ?? [],
            'closes_at' => !empty($_POST['closes_at']) ? $_POST['closes_at'] : null,
            'created_by' => $memberId
        ];

        // Validate
        $errors = $this->pollModel->validate($data);

        if (!empty($errors)) {
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_data'] = $data;
            header('Location: ' . url('/polls/create'));
            exit;
        }

        // Create the poll
        $pollId = $this->pollModel->create($data);

        // Log the action
        $this->auditLog->log(
            $brigadeId,
            $memberId,
            'poll.create',
            ['poll_id' => $pollId, 'title' => $data['title']]
        );

        $_SESSION['flash_message'] = 'Poll created successfully.';
        $_SESSION['flash_type'] = 'success';

        header('Location: ' . url('/polls/' . $pollId));
        exit;
    }

    /**
     * Submit or update a vote
     * POST /polls/{id}/vote
     */
    public function vote(string $id): void
    {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        // Verify CSRF
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            $this->redirectWithError($id, 'Invalid request. Please try again.');
            return;
        }

        $pollId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];
        $memberId = (int)$user['id'];

        $poll = $this->pollModel->findById($pollId);

        if (!$poll || !$this->pollModel->belongsToBrigade($pollId, $brigadeId)) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Poll not found']);
            return;
        }

        if ($poll['status'] !== 'active') {
            $this->redirectWithError($id, 'This poll is closed and no longer accepting votes.');
            return;
        }

        // Get selected options
        $selectedOptions = $_POST['options'] ?? [];
        if (!is_array($selectedOptions)) {
            $selectedOptions = [$selectedOptions];
        }

        // Filter to valid integers
        $selectedOptions = array_filter(array_map('intval', $selectedOptions));

        if (empty($selectedOptions)) {
            $this->redirectWithError($id, 'Please select at least one option.');
            return;
        }

        // For single-choice polls, only allow one option
        if ($poll['type'] === 'single' && count($selectedOptions) > 1) {
            $this->redirectWithError($id, 'Only one option can be selected for this poll.');
            return;
        }

        // Validate that options belong to this poll
        $validOptionIds = array_column($poll['options'], 'id');
        foreach ($selectedOptions as $optionId) {
            if (!in_array($optionId, $validOptionIds)) {
                $this->redirectWithError($id, 'Invalid option selected.');
                return;
            }
        }

        // Clear previous votes and submit new ones
        $this->pollModel->clearVotes($pollId, $memberId);

        foreach ($selectedOptions as $optionId) {
            $this->pollModel->vote($pollId, $optionId, $memberId);
        }

        // Set success message
        $_SESSION['flash_message'] = 'Your vote has been recorded.';
        $_SESSION['flash_type'] = 'success';

        header('Location: ' . url('/polls/' . $id));
        exit;
    }

    /**
     * Show edit poll form
     * GET /polls/{id}/edit
     */
    public function edit(string $id): void
    {
        $user = currentUser();
        if (!$user) {
            header('Location: ' . url('/auth/login'));
            exit;
        }

        $pollId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];

        $poll = $this->pollModel->findById($pollId);

        if (!$poll || !$this->pollModel->belongsToBrigade($pollId, $brigadeId)) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Poll not found']);
            return;
        }

        // Check if user can edit (creator or admin)
        $canEdit = hasRole('admin') || (int)$poll['created_by'] === (int)$user['id'];
        if (!$canEdit) {
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        render('pages/polls/edit', [
            'pageTitle' => 'Edit Poll',
            'poll' => $poll
        ]);
    }

    /**
     * Update an existing poll
     * PUT /polls/{id}
     */
    public function update(string $id): void
    {
        $user = currentUser();
        if (!$user) {
            header('Location: ' . url('/auth/login'));
            exit;
        }

        // Verify CSRF
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            $_SESSION['flash_message'] = 'Invalid request. Please try again.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . url('/polls/' . $id . '/edit'));
            exit;
        }

        $pollId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];
        $memberId = (int)$user['id'];

        $poll = $this->pollModel->findById($pollId);

        if (!$poll || !$this->pollModel->belongsToBrigade($pollId, $brigadeId)) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Poll not found']);
            return;
        }

        // Check if user can edit (creator or admin)
        $canEdit = hasRole('admin') || (int)$poll['created_by'] === $memberId;
        if (!$canEdit) {
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'type' => $_POST['type'] ?? 'single',
            'options' => $_POST['options'] ?? [],
            'closes_at' => !empty($_POST['closes_at']) ? $_POST['closes_at'] : null,
        ];

        // Validate
        $errors = $this->pollModel->validate($data);

        if (!empty($errors)) {
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_data'] = $data;
            header('Location: ' . url('/polls/' . $id . '/edit'));
            exit;
        }

        // Update the poll
        $this->pollModel->update($pollId, $data);

        // Update options (this will clear votes if options changed)
        $this->pollModel->updateOptions($pollId, $data['options']);

        // Log the action
        $this->auditLog->log(
            $brigadeId,
            $memberId,
            'poll.update',
            ['poll_id' => $pollId, 'title' => $data['title']]
        );

        $_SESSION['flash_message'] = 'Poll updated successfully.';
        $_SESSION['flash_type'] = 'success';

        header('Location: ' . url('/polls/' . $id));
        exit;
    }

    /**
     * Close a poll
     * POST /polls/{id}/close
     */
    public function close(string $id): void
    {
        $user = currentUser();
        if (!$user) {
            header('Location: ' . url('/auth/login'));
            exit;
        }

        // Verify CSRF
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            $_SESSION['flash_message'] = 'Invalid request. Please try again.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . url('/polls/' . $id));
            exit;
        }

        $pollId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];
        $memberId = (int)$user['id'];

        $poll = $this->pollModel->findById($pollId);

        if (!$poll || !$this->pollModel->belongsToBrigade($pollId, $brigadeId)) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Poll not found']);
            return;
        }

        // Check if user can close (creator or admin)
        $canEdit = hasRole('admin') || (int)$poll['created_by'] === $memberId;
        if (!$canEdit) {
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        $this->pollModel->close($pollId);

        // Log the action
        $this->auditLog->log(
            $brigadeId,
            $memberId,
            'poll.close',
            ['poll_id' => $pollId, 'title' => $poll['title']]
        );

        $_SESSION['flash_message'] = 'Poll has been closed.';
        $_SESSION['flash_type'] = 'success';

        header('Location: ' . url('/polls/' . $id));
        exit;
    }

    /**
     * Delete a poll
     * DELETE /polls/{id}
     */
    public function destroy(string $id): void
    {
        $user = currentUser();
        if (!$user) {
            header('Location: ' . url('/auth/login'));
            exit;
        }

        // Verify CSRF
        if (!verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
            $_SESSION['flash_message'] = 'Invalid request. Please try again.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . url('/polls/' . $id));
            exit;
        }

        $pollId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];
        $memberId = (int)$user['id'];

        $poll = $this->pollModel->findById($pollId);

        if (!$poll || !$this->pollModel->belongsToBrigade($pollId, $brigadeId)) {
            http_response_code(404);
            render('pages/errors/404', ['message' => 'Poll not found']);
            return;
        }

        // Check if user can delete (creator or admin)
        $canDelete = hasRole('admin') || (int)$poll['created_by'] === $memberId;
        if (!$canDelete) {
            http_response_code(403);
            render('pages/errors/403');
            return;
        }

        $this->pollModel->delete($pollId);

        // Log the action
        $this->auditLog->log(
            $brigadeId,
            $memberId,
            'poll.delete',
            ['poll_id' => $pollId, 'title' => $poll['title']]
        );

        $_SESSION['flash_message'] = 'Poll has been deleted.';
        $_SESSION['flash_type'] = 'success';

        header('Location: ' . url('/polls'));
        exit;
    }

    /**
     * Helper to redirect with error message
     */
    private function redirectWithError(string $id, string $message): void
    {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = 'error';
        header('Location: ' . url('/polls/' . $id));
        exit;
    }
}
