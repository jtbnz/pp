<?php
declare(strict_types=1);

/**
 * Notices Index Page
 *
 * Lists all active notices for the brigade.
 *
 * Variables:
 * - $notices: array - List of notices
 * - $totalNotices: int - Total count
 * - $isAdmin: bool - Whether user is admin
 */

global $config;

$pageTitle = $pageTitle ?? 'Notices';
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';

// Get flash message if any
$flashMessage = null;
$flashType = 'info';
if (isset($_SESSION['flash'])) {
    $flashMessage = $_SESSION['flash']['message'] ?? null;
    $flashType = $_SESSION['flash']['type'] ?? 'info';
    unset($_SESSION['flash']);
}

// Start output buffering for content
ob_start();
?>

<div class="page-notices">
    <header class="page-header">
        <div class="page-header-content">
            <h1><?= e($pageTitle) ?></h1>
            <a href="<?= url('/notices/create') ?>" class="btn btn-primary">
                + New Notice
            </a>
        </div>
    </header>

    <?php if ($flashMessage): ?>
        <div class="flash-message flash-<?= e($flashType) ?>">
            <?= e($flashMessage) ?>
            <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
        <!-- Filter controls for admins -->
        <div class="notice-filters card mb-4">
            <div class="card-body">
                <form method="GET" action="<?= url('/notices') ?>" class="filter-form">
                    <div class="filter-row">
                        <div class="form-group mb-0">
                            <label for="filter-type" class="form-label">Type</label>
                            <select id="filter-type" name="type" class="form-select">
                                <option value="">All Types</option>
                                <option value="standard" <?= ($_GET['type'] ?? '') === 'standard' ? 'selected' : '' ?>>Standard</option>
                                <option value="sticky" <?= ($_GET['type'] ?? '') === 'sticky' ? 'selected' : '' ?>>Sticky</option>
                                <option value="timed" <?= ($_GET['type'] ?? '') === 'timed' ? 'selected' : '' ?>>Timed</option>
                                <option value="urgent" <?= ($_GET['type'] ?? '') === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                            </select>
                        </div>
                        <div class="form-group mb-0">
                            <label for="filter-search" class="form-label">Search</label>
                            <input type="text" id="filter-search" name="search" class="form-input" placeholder="Search notices..." value="<?= e($_GET['search'] ?? '') ?>">
                        </div>
                        <div class="form-group mb-0 filter-actions">
                            <button type="submit" class="btn btn-secondary">Filter</button>
                            <a href="<?= url('/notices') ?>" class="btn">Clear</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="notices-container" id="notices-list">
        <?php if (empty($notices)): ?>
            <div class="empty-state card">
                <div class="card-body text-center p-5">
                    <p class="text-secondary mb-3">No notices to display</p>
                    <?php if ($isAdmin): ?>
                        <a href="<?= url('/notices/create') ?>" class="btn btn-primary">Create your first notice</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="notices-list">
                <?php foreach ($notices as $notice): ?>
                    <?php
                    $showActions = $isAdmin;
                    include __DIR__ . '/../../partials/notice-card.php';
                    ?>
                <?php endforeach; ?>
            </div>

            <?php if ($totalNotices > count($notices)): ?>
                <div class="load-more-container text-center mt-4">
                    <button type="button" class="btn btn-outline" id="load-more-btn" data-page="2">
                        Load More
                    </button>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();

// Extra scripts
$extraScripts = '<script src="' . url('/assets/js/notices.js') . '"></script>';

// Include main layout
require __DIR__ . '/../../layouts/main.php';
?>
