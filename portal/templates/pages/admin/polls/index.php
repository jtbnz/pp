<?php
declare(strict_types=1);

/**
 * Admin Polls List
 *
 * Lists all polls for management.
 *
 * Variables:
 * - $polls: array - List of polls
 * - $totalCount: int - Total count
 * - $status: string|null - Current filter
 */

global $config;

$pageTitle = $pageTitle ?? 'Manage Polls';
$appName = $config['app_name'] ?? 'Puke Portal';
$user = currentUser();

// Get flash message if any
$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// Start output buffering for content
ob_start();
?>

<div class="page-admin-polls">
    <header class="page-header">
        <div class="page-header-content">
            <h1><?= e($pageTitle) ?></h1>
            <a href="<?= url('/admin/polls/create') ?>" class="btn btn-primary">+ New Poll</a>
        </div>
    </header>

    <?php if ($flashMessage): ?>
        <div class="flash-message flash-<?= e($flashType) ?>">
            <?= e($flashMessage) ?>
            <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Filter tabs -->
    <div class="poll-filters mb-4">
        <div class="filter-tabs">
            <a href="<?= url('/admin/polls') ?>" class="filter-tab <?= $status === null ? 'active' : '' ?>">All</a>
            <a href="<?= url('/admin/polls?status=active') ?>" class="filter-tab <?= $status === 'active' ? 'active' : '' ?>">Active</a>
            <a href="<?= url('/admin/polls?status=closed') ?>" class="filter-tab <?= $status === 'closed' ? 'active' : '' ?>">Closed</a>
        </div>
    </div>

    <div class="polls-container">
        <?php if (empty($polls)): ?>
            <div class="empty-state card">
                <div class="card-body text-center p-5">
                    <p class="text-secondary mb-3">No polls found</p>
                    <a href="<?= url('/admin/polls/create') ?>" class="btn btn-primary">Create your first poll</a>
                </div>
            </div>
        <?php else: ?>
            <div class="polls-table card">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Votes</th>
                            <th>Closes</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($polls as $poll): ?>
                            <tr>
                                <td>
                                    <a href="<?= url('/polls/' . $poll['id']) ?>" class="poll-title-link">
                                        <?= e($poll['title']) ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="badge badge-outline">
                                        <?= $poll['type'] === 'multi' ? 'Multi' : 'Single' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($poll['status'] === 'active'): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Closed</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $poll['total_votes'] ?></td>
                                <td>
                                    <?php if ($poll['closes_at']): ?>
                                        <?= fromUtc($poll['closes_at'], 'j M Y, g:ia') ?>
                                    <?php else: ?>
                                        <span class="text-secondary">No expiry</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= timeAgo($poll['created_at']) ?></td>
                                <td class="actions-cell">
                                    <a href="<?= url('/admin/polls/' . $poll['id']) ?>" class="btn btn-sm">Edit</a>
                                    <?php if ($poll['status'] === 'active'): ?>
                                        <form method="POST" action="<?= url('/admin/polls/' . $poll['id'] . '/close') ?>" class="inline-form" onsubmit="return confirm('Are you sure you want to close this poll?');">
                                            <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                                            <button type="submit" class="btn btn-sm btn-secondary">Close</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" action="<?= url('/admin/polls/' . $poll['id']) ?>" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this poll? This action cannot be undone.');">
                                        <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="admin-nav-back mt-4">
        <a href="<?= url('/admin') ?>" class="btn btn-text">&larr; Back to Dashboard</a>
    </div>
</div>

<style>
.page-header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.filter-tabs {
    display: flex;
    gap: 0.5rem;
    border-bottom: 1px solid var(--border-color, #e0e0e0);
    padding-bottom: 0.5rem;
}

.filter-tab {
    padding: 0.5rem 1rem;
    color: var(--text-secondary);
    text-decoration: none;
    border-radius: 4px 4px 0 0;
    transition: all 0.2s;
}

.filter-tab:hover {
    color: var(--primary);
    background: var(--bg-secondary);
}

.filter-tab.active {
    color: var(--primary);
    border-bottom: 2px solid var(--primary);
    margin-bottom: -0.5rem;
    padding-bottom: calc(0.5rem + 1px);
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table th,
.table td {
    padding: 0.75rem 1rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}

.table th {
    font-weight: 600;
    background: var(--bg-secondary);
}

.poll-title-link {
    color: var(--primary);
    text-decoration: none;
    font-weight: 500;
}

.poll-title-link:hover {
    text-decoration: underline;
}

.badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 4px;
}

.badge-success {
    background: var(--success, #4caf50);
    color: white;
}

.badge-secondary {
    background: var(--text-secondary);
    color: white;
}

.badge-outline {
    background: transparent;
    border: 1px solid var(--border-color);
    color: var(--text-secondary);
}

.actions-cell {
    display: flex;
    gap: 0.5rem;
}

.inline-form {
    display: inline;
}

.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: 0.8125rem;
}

.btn-danger {
    background: var(--error, #f44336);
    color: white;
}

.btn-danger:hover {
    background: #d32f2f;
}
</style>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../../layouts/main.php';
?>
