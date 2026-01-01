<?php
declare(strict_types=1);

/**
 * Admin Notices List Template
 *
 * Notice list with type filter and management actions.
 */

global $config;

$pageTitle = $pageTitle ?? 'Manage Notices';
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';
$user = currentUser();

// Get flash messages
$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// Start output buffering for content
ob_start();
?>

<div class="page-admin-notices">
    <header class="page-header">
        <div class="header-row">
            <div>
                <h1>Notices</h1>
                <p class="text-secondary"><?= $totalCount ?> notice<?= $totalCount !== 1 ? 's' : '' ?></p>
            </div>
            <a href="<?= url('/admin/notices/create') ?>" class="btn btn-primary">
                <span class="btn-icon">&#43;</span> Create
            </a>
        </div>
    </header>

    <?php if ($flashMessage): ?>
    <div class="flash-message flash-<?= e($flashType) ?>">
        <?= e($flashMessage) ?>
        <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Filter Section -->
    <section class="filters-section mb-3">
        <form method="GET" action="<?= url('/admin/notices') ?>" class="filters-form">
            <div class="filter-group">
                <label for="type" class="form-label-inline">Type:</label>
                <select id="type" name="type" class="form-select">
                    <option value="">All Types</option>
                    <option value="standard" <?= ($filters['type'] ?? '') === 'standard' ? 'selected' : '' ?>>Standard</option>
                    <option value="sticky" <?= ($filters['type'] ?? '') === 'sticky' ? 'selected' : '' ?>>Sticky</option>
                    <option value="timed" <?= ($filters['type'] ?? '') === 'timed' ? 'selected' : '' ?>>Timed</option>
                    <option value="urgent" <?= ($filters['type'] ?? '') === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                </select>
            </div>
            <div class="filter-group">
                <input type="text" name="search" class="form-input" placeholder="Search notices..."
                       value="<?= e($filters['search'] ?? '') ?>">
            </div>
            <button type="submit" class="btn btn-secondary">Filter</button>
            <?php if (!empty($filters)): ?>
                <a href="<?= url('/admin/notices') ?>" class="btn btn-text">Clear</a>
            <?php endif; ?>
        </form>
    </section>

    <!-- Notices List -->
    <section class="notices-list">
        <?php if (empty($notices)): ?>
        <div class="card">
            <div class="card-body text-center p-4">
                <p class="text-secondary">No notices found</p>
                <a href="<?= url('/admin/notices/create') ?>" class="btn btn-primary mt-2">Create Notice</a>
            </div>
        </div>
        <?php else: ?>
        <div class="notices-grid">
            <?php foreach ($notices as $notice): ?>
            <div class="notice-card card <?= $notice['type'] === 'urgent' ? 'notice-urgent' : '' ?>">
                <div class="notice-header">
                    <div class="notice-badges">
                        <span class="badge badge-<?= e($notice['type']) ?>"><?= ucfirst(e($notice['type'])) ?></span>
                        <?php
                        $now = new DateTime();
                        $displayFrom = $notice['display_from'] ? new DateTime($notice['display_from']) : null;
                        $displayTo = $notice['display_to'] ? new DateTime($notice['display_to']) : null;

                        if ($displayFrom && $displayFrom > $now): ?>
                            <span class="badge badge-scheduled">Scheduled</span>
                        <?php elseif ($displayTo && $displayTo < $now): ?>
                            <span class="badge badge-expired">Expired</span>
                        <?php else: ?>
                            <span class="badge badge-active">Active</span>
                        <?php endif; ?>
                    </div>
                    <div class="notice-actions">
                        <a href="<?= url('/notices/' . $notice['id'] . '/edit') ?>" class="btn btn-sm btn-secondary">Edit</a>
                        <form action="<?= url('/notices/' . $notice['id']) ?>" method="POST" class="inline-form"
                              onsubmit="return confirm('Are you sure you want to delete this notice?')">
                            <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="_method" value="DELETE">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </div>
                </div>
                <h3 class="notice-title"><?= e($notice['title']) ?></h3>
                <?php if ($notice['content']): ?>
                <p class="notice-excerpt"><?= e(substr(strip_tags($notice['content']), 0, 150)) ?><?= strlen($notice['content']) > 150 ? '...' : '' ?></p>
                <?php endif; ?>
                <div class="notice-meta">
                    <span class="notice-date">Created <?= (new DateTime($notice['created_at']))->format('j M Y') ?></span>
                    <?php if ($notice['display_from'] || $notice['display_to']): ?>
                    <span class="notice-schedule">
                        <?php if ($notice['display_from']): ?>
                            From <?= (new DateTime($notice['display_from']))->format('j M') ?>
                        <?php endif; ?>
                        <?php if ($notice['display_to']): ?>
                            until <?= (new DateTime($notice['display_to']))->format('j M') ?>
                        <?php endif; ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <div class="admin-nav-back mt-4">
        <a href="<?= url('/admin') ?>" class="btn btn-text">&larr; Back to Dashboard</a>
    </div>
</div>

<style>
.header-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.filters-form {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.form-label-inline {
    font-weight: 500;
    white-space: nowrap;
}

.notices-grid {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.notice-card {
    padding: 1rem;
}

.notice-card.notice-urgent {
    border-left: 4px solid var(--error, #D32F2F);
}

.notice-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.5rem;
}

.notice-badges {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.notice-actions {
    display: flex;
    gap: 0.5rem;
}

.notice-title {
    margin: 0.5rem 0;
    font-size: 1.125rem;
}

.notice-excerpt {
    color: var(--text-secondary);
    font-size: 0.875rem;
    margin: 0.5rem 0;
    line-height: 1.5;
}

.notice-meta {
    display: flex;
    gap: 1rem;
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-top: 0.75rem;
}

.badge {
    display: inline-block;
    padding: 0.125rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 4px;
    background: var(--bg-secondary, #e0e0e0);
}

.badge-standard {
    background: var(--bg-secondary, #e0e0e0);
}

.badge-sticky {
    background: #2196F3;
    color: white;
}

.badge-timed {
    background: #FF9800;
    color: white;
}

.badge-urgent {
    background: var(--error, #D32F2F);
    color: white;
}

.badge-active {
    background: #4CAF50;
    color: white;
}

.badge-scheduled {
    background: #9C27B0;
    color: white;
}

.badge-expired {
    background: #757575;
    color: white;
}

.inline-form {
    display: inline;
}

.btn-danger {
    background: var(--error, #D32F2F);
    color: white;
}

.btn-danger:hover {
    background: #B71C1C;
}

@media (max-width: 600px) {
    .notice-header {
        flex-direction: column;
        gap: 0.5rem;
    }

    .notice-actions {
        width: 100%;
    }
}
</style>

<script>
// Dismiss flash messages
document.querySelectorAll('.flash-dismiss').forEach(btn => {
    btn.addEventListener('click', function() {
        this.closest('.flash-message').remove();
    });
});
</script>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../../layouts/main.php';
?>
