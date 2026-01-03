<?php
declare(strict_types=1);

/**
 * Polls Index Page
 *
 * Lists all polls for the brigade.
 *
 * Variables:
 * - $polls: array - List of polls with options and vote counts
 * - $status: string - Current filter status
 * - $canCreate: bool - Whether user can create polls (officer+)
 */

global $config;

$pageTitle = $pageTitle ?? 'Polls';
$appName = $config['app_name'] ?? 'Puke Portal';
$user = currentUser();

// Get flash message if any
$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// Start output buffering for content
ob_start();
?>

<div class="page-polls">
    <header class="page-header">
        <div class="page-header-content">
            <h1><?= e($pageTitle) ?></h1>
            <a href="<?= url('/polls/create') ?>" class="btn btn-primary">
                + New Poll
            </a>
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
            <a href="<?= url('/polls') ?>" class="filter-tab <?= $status === 'active' ? 'active' : '' ?>">Active</a>
            <a href="<?= url('/polls?status=closed') ?>" class="filter-tab <?= $status === 'closed' ? 'active' : '' ?>">Closed</a>
            <a href="<?= url('/polls?status=all') ?>" class="filter-tab <?= $status === 'all' ? 'active' : '' ?>">All</a>
        </div>
    </div>

    <div class="polls-container">
        <?php if (empty($polls)): ?>
            <div class="empty-state card">
                <div class="card-body text-center p-5">
                    <p class="text-secondary mb-3">No polls to display</p>
                    <?php if ($canCreate): ?>
                        <a href="<?= url('/admin/polls/create') ?>" class="btn btn-primary">Create your first poll</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="polls-list">
                <?php foreach ($polls as $poll): ?>
                    <div class="poll-card card mb-3 <?= $poll['status'] === 'closed' ? 'poll-closed' : '' ?>">
                        <a href="<?= url('/polls/' . $poll['id']) ?>" class="poll-card-link">
                            <div class="card-body">
                                <div class="poll-header">
                                    <h3 class="poll-title"><?= e($poll['title']) ?></h3>
                                    <div class="poll-badges">
                                        <?php if ($poll['status'] === 'closed'): ?>
                                            <span class="badge badge-secondary">Closed</span>
                                        <?php elseif ($poll['has_voted']): ?>
                                            <span class="badge badge-success">Voted</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Not voted</span>
                                        <?php endif; ?>
                                        <span class="badge badge-outline"><?= $poll['type'] === 'multi' ? 'Multi-choice' : 'Single choice' ?></span>
                                    </div>
                                </div>

                                <?php if ($poll['description']): ?>
                                    <p class="poll-description text-secondary"><?= e(substr($poll['description'], 0, 150)) ?><?= strlen($poll['description']) > 150 ? '...' : '' ?></p>
                                <?php endif; ?>

                                <div class="poll-meta">
                                    <span class="poll-votes">
                                        <?= $poll['total_votes'] ?> vote<?= $poll['total_votes'] !== 1 ? 's' : '' ?>
                                    </span>
                                    <?php if ($poll['closes_at']): ?>
                                        <?php
                                        $closesAt = strtotime($poll['closes_at']);
                                        $nowUtc = strtotime(nowUtc());
                                        $remaining = $closesAt - $nowUtc;
                                        ?>
                                        <?php if ($remaining > 0 && $poll['status'] === 'active'): ?>
                                            <span class="poll-expires">
                                                Closes in <?= formatPollTimeRemaining($remaining) ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <span class="poll-date">
                                        Created <?= timeAgo($poll['created_at']) ?>
                                    </span>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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

.poll-filters {
    margin-bottom: 1.5rem;
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

.poll-card {
    transition: transform 0.2s, box-shadow 0.2s;
}

.poll-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.poll-card-link {
    display: block;
    text-decoration: none;
    color: inherit;
}

.poll-closed {
    opacity: 0.7;
}

.poll-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 0.5rem;
}

.poll-title {
    margin: 0;
    font-size: 1.125rem;
    font-weight: 600;
}

.poll-badges {
    display: flex;
    gap: 0.5rem;
    flex-shrink: 0;
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

.badge-warning {
    background: var(--warning, #ff9800);
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

.poll-description {
    margin: 0.5rem 0;
    font-size: 0.9375rem;
    line-height: 1.4;
}

.poll-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    font-size: 0.8125rem;
    color: var(--text-secondary);
    margin-top: 0.75rem;
}

.empty-state {
    text-align: center;
    padding: 3rem 1rem;
}
</style>

<?php
// Helper function
function formatPollTimeRemaining(int $seconds): string
{
    if ($seconds < 3600) {
        $mins = (int)floor($seconds / 60);
        return $mins . ' min' . ($mins !== 1 ? 's' : '');
    }

    if ($seconds < 86400) {
        $hours = (int)floor($seconds / 3600);
        return $hours . ' hour' . ($hours !== 1 ? 's' : '');
    }

    $days = (int)floor($seconds / 86400);
    return $days . ' day' . ($days !== 1 ? 's' : '');
}

$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../layouts/main.php';
?>
