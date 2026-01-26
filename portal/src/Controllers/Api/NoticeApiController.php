<?php
declare(strict_types=1);

namespace Portal\Controllers\Api;

use Portal\Models\Notice;
use Portal\Helpers\Markdown;

/**
 * Notice API Controller
 *
 * Handles JSON API endpoints for notices.
 */
class NoticeApiController
{
    private Notice $noticeModel;

    public function __construct()
    {
        $this->noticeModel = new Notice();
    }

    /**
     * Get list of notices
     * GET /api/notices
     */
    public function index(): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $brigadeId = (int)$user['brigade_id'];

        // Parse query parameters
        $filters = [];

        if (!empty($_GET['type'])) {
            $filters['type'] = $_GET['type'];
        }

        if (!empty($_GET['search'])) {
            $filters['search'] = $_GET['search'];
        }

        // Only admins can see inactive notices
        if (!hasRole('admin') || empty($_GET['include_inactive'])) {
            $filters['active_only'] = true;
        }

        // Pagination
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(50, max(1, (int)($_GET['per_page'] ?? 20)));
        $filters['limit'] = $perPage;
        $filters['offset'] = ($page - 1) * $perPage;

        $notices = $this->noticeModel->findAll($brigadeId, $filters);
        $total = $this->noticeModel->count($brigadeId, array_diff_key($filters, ['limit' => 1, 'offset' => 1]));

        // Format notices for API response
        $formattedNotices = array_map(function ($notice) {
            return $this->formatNotice($notice);
        }, $notices);

        jsonResponse([
            'data' => $formattedNotices,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int)ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * Get a single notice
     * GET /api/notices/{id}
     */
    public function show(string $id): void
    {
        $user = currentUser();

        if (!$user) {
            jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $noticeId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];

        $notice = $this->noticeModel->findById($noticeId);

        if (!$notice || $notice['brigade_id'] !== $brigadeId) {
            jsonResponse(['error' => 'Notice not found'], 404);
            return;
        }

        // Check if notice is active (unless admin)
        if (!hasRole('admin') && !$this->noticeModel->isActive($notice)) {
            jsonResponse(['error' => 'Notice not found'], 404);
            return;
        }

        jsonResponse([
            'data' => $this->formatNotice($notice, true),
        ]);
    }

    /**
     * Create a new notice
     * POST /api/notices
     */
    public function store(): void
    {
        $user = currentUser();

        if (!$user || !hasRole('admin')) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $data = [
            'brigade_id' => (int)$user['brigade_id'],
            'title' => trim($input['title'] ?? ''),
            'content' => trim($input['content'] ?? ''),
            'type' => $input['type'] ?? 'standard',
            'display_from' => !empty($input['display_from']) ? $input['display_from'] : null,
            'display_to' => !empty($input['display_to']) ? $input['display_to'] : null,
            'author_id' => (int)$user['id'],
        ];

        // Validate
        $errors = $this->noticeModel->validate($data);

        if (!empty($errors)) {
            jsonResponse(['error' => 'Validation failed', 'errors' => $errors], 422);
            return;
        }

        // Create the notice
        $noticeId = $this->noticeModel->create($data);

        // Fetch the created notice
        $notice = $this->noticeModel->findById($noticeId);

        jsonResponse([
            'data' => $this->formatNotice($notice, true),
            'message' => 'Notice created successfully',
        ], 201);
    }

    /**
     * Update a notice
     * PUT /api/notices/{id}
     */
    public function update(string $id): void
    {
        $user = currentUser();

        if (!$user || !hasRole('admin')) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $noticeId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];

        $notice = $this->noticeModel->findById($noticeId);

        if (!$notice || $notice['brigade_id'] !== $brigadeId) {
            jsonResponse(['error' => 'Notice not found'], 404);
            return;
        }

        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $data = [];

        if (isset($input['title'])) {
            $data['title'] = trim($input['title']);
        }

        if (isset($input['content'])) {
            $data['content'] = trim($input['content']);
        }

        if (isset($input['type'])) {
            $data['type'] = $input['type'];
        }

        if (array_key_exists('display_from', $input)) {
            $data['display_from'] = !empty($input['display_from']) ? $input['display_from'] : null;
        }

        if (array_key_exists('display_to', $input)) {
            $data['display_to'] = !empty($input['display_to']) ? $input['display_to'] : null;
        }

        // Validate if we have data to update
        if (!empty($data)) {
            // Merge with existing data for validation
            $validationData = array_merge($notice, $data);
            $errors = $this->noticeModel->validate($validationData);

            if (!empty($errors)) {
                jsonResponse(['error' => 'Validation failed', 'errors' => $errors], 422);
                return;
            }

            $this->noticeModel->update($noticeId, $data);
        }

        // Fetch updated notice
        $notice = $this->noticeModel->findById($noticeId);

        jsonResponse([
            'data' => $this->formatNotice($notice, true),
            'message' => 'Notice updated successfully',
        ]);
    }

    /**
     * Delete a notice
     * DELETE /api/notices/{id}
     */
    public function destroy(string $id): void
    {
        $user = currentUser();

        if (!$user || !hasRole('admin')) {
            jsonResponse(['error' => 'Forbidden'], 403);
            return;
        }

        $noticeId = (int)$id;
        $brigadeId = (int)$user['brigade_id'];

        $notice = $this->noticeModel->findById($noticeId);

        if (!$notice || $notice['brigade_id'] !== $brigadeId) {
            jsonResponse(['error' => 'Notice not found'], 404);
            return;
        }

        $this->noticeModel->delete($noticeId);

        jsonResponse([
            'success' => true,
            'message' => 'Notice deleted successfully',
        ]);
    }

    /**
     * Format a notice for API response
     */
    private function formatNotice(array $notice, bool $includeHtml = false): array
    {
        $formatted = [
            'id' => (int)$notice['id'],
            'title' => $notice['title'],
            'content' => $notice['content'],
            'type' => $notice['type'],
            'display_from' => $notice['display_from'],
            'display_to' => $notice['display_to'],
            'is_active' => $this->noticeModel->isActive($notice),
            'remaining_seconds' => $this->noticeModel->getRemainingSeconds($notice),
            'author' => [
                'id' => $notice['author_id'] ? (int)$notice['author_id'] : null,
                'name' => $notice['author_name'] ?? null,
            ],
            'created_at' => $notice['created_at'],
            'updated_at' => $notice['updated_at'],
        ];

        if ($includeHtml && !empty($notice['content'])) {
            $formatted['content_html'] = Markdown::render($notice['content']);
        }

        // Add excerpt for list views
        if (!empty($notice['content'])) {
            $formatted['excerpt'] = Markdown::truncate($notice['content'], 150);
        } else {
            $formatted['excerpt'] = '';
        }

        return $formatted;
    }
}
