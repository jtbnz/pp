<?php
declare(strict_types=1);

/**
 * Notice Detail Page
 *
 * Shows a single notice with full content.
 *
 * Variables:
 * - $notice: array - The notice data
 * - $remainingSeconds: int|null - Remaining time for timed notices
 * - $isAdmin: bool - Whether user is admin
 */

global $config;

require_once __DIR__ . '/../../../src/Helpers/Markdown.php';

$pageTitle = $pageTitle ?? $notice['title'];
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

// Check if notice is currently active
$isActive = true;
$now = time();
if (!empty($notice['display_from']) && strtotime($notice['display_from']) > $now) {
    $isActive = false;
}
if (!empty($notice['display_to']) && strtotime($notice['display_to']) < $now) {
    $isActive = false;
}

// Start output buffering for content
ob_start();
?>

<div class="page-notice-detail">
    <nav class="breadcrumb-nav mb-3">
        <a href="<?= url('/notices') ?>" class="breadcrumb-link">&larr; Back to Notices</a>
    </nav>

    <?php if ($flashMessage): ?>
        <div class="flash-message flash-<?= e($flashType) ?>">
            <?= e($flashMessage) ?>
            <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <article class="notice-detail notice-type-<?= e($notice['type']) ?>" data-notice-id="<?= (int)$notice['id'] ?>" <?php if ($remainingSeconds): ?>data-expires-in="<?= $remainingSeconds ?>"<?php endif; ?>>
        <header class="notice-detail-header">
            <div class="notice-badges">
                <?php if ($notice['type'] === 'sticky'): ?>
                    <span class="notice-badge notice-badge-sticky">
                        <span class="badge-icon">&#128204;</span>
                        Pinned
                    </span>
                <?php elseif ($notice['type'] === 'urgent'): ?>
                    <span class="notice-badge notice-badge-urgent">
                        <span class="badge-icon">&#9888;</span>
                        Urgent
                    </span>
                <?php elseif ($notice['type'] === 'timed' && $remainingSeconds): ?>
                    <span class="notice-badge notice-badge-timed">
                        <span class="badge-icon">&#9201;</span>
                        Expires in: <span class="notice-countdown" data-seconds="<?= $remainingSeconds ?>"><?= formatDetailCountdown($remainingSeconds) ?></span>
                    </span>
                <?php endif; ?>

                <?php if (!$isActive): ?>
                    <span class="notice-badge notice-badge-inactive">
                        Inactive
                    </span>
                <?php endif; ?>
            </div>

            <h1 class="notice-detail-title"><?= e($notice['title']) ?></h1>

            <div class="notice-detail-meta">
                <?php if (!empty($notice['author_name'])): ?>
                    <span class="notice-author">Posted by <?= e($notice['author_name']) ?></span>
                    <span class="meta-separator">&middot;</span>
                <?php endif; ?>
                <time class="notice-date" datetime="<?= e($notice['created_at']) ?>" title="<?= e($notice['created_at']) ?>">
                    <?= timeAgo($notice['created_at']) ?>
                </time>
                <?php if ($notice['updated_at'] !== $notice['created_at']): ?>
                    <span class="meta-separator">&middot;</span>
                    <span class="notice-updated" title="Last updated: <?= e($notice['updated_at']) ?>">
                        (edited)
                    </span>
                <?php endif; ?>
            </div>
        </header>

        <?php if (!empty($notice['content'])): ?>
            <div class="notice-detail-content markdown-content">
                <?= Markdown::render($notice['content']) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($notice['display_from']) || !empty($notice['display_to'])): ?>
            <div class="notice-detail-schedule">
                <h4>Display Schedule</h4>
                <dl class="schedule-info">
                    <?php if (!empty($notice['display_from'])): ?>
                        <dt>From:</dt>
                        <dd><?= date('l, j F Y \a\t g:i A', strtotime($notice['display_from'])) ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($notice['display_to'])): ?>
                        <dt>Until:</dt>
                        <dd><?= date('l, j F Y \a\t g:i A', strtotime($notice['display_to'])) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
            <footer class="notice-detail-actions">
                <a href="<?= url('/notices/' . (int)$notice['id'] . '/edit') ?>" class="btn btn-primary">
                    Edit Notice
                </a>
                <form action="<?= url('/notices/' . (int)$notice['id']) ?>" method="POST" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this notice? This action cannot be undone.')">
                    <input type="hidden" name="_method" value="DELETE">
                    <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                    <button type="submit" class="btn btn-danger">
                        Delete Notice
                    </button>
                </form>
            </footer>
        <?php endif; ?>
    </article>
</div>

<?php
$content = ob_get_clean();

// Extra scripts
$extraScripts = '<script src="' . url('/assets/js/notices.js') . '"></script>';

// Include main layout
require __DIR__ . '/../../layouts/main.php';

/**
 * Format countdown time (detailed version)
 */
function formatDetailCountdown(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . ' seconds';
    }

    if ($seconds < 3600) {
        $minutes = (int)floor($seconds / 60);
        return $minutes . ' minute' . ($minutes !== 1 ? 's' : '');
    }

    if ($seconds < 86400) {
        $hours = (int)floor($seconds / 3600);
        $minutes = (int)floor(($seconds % 3600) / 60);
        $result = $hours . ' hour' . ($hours !== 1 ? 's' : '');
        if ($minutes > 0) {
            $result .= ' ' . $minutes . ' min';
        }
        return $result;
    }

    $days = (int)floor($seconds / 86400);
    $hours = (int)floor(($seconds % 86400) / 3600);
    $result = $days . ' day' . ($days !== 1 ? 's' : '');
    if ($hours > 0) {
        $result .= ' ' . $hours . ' hr' . ($hours !== 1 ? 's' : '');
    }
    return $result;
}
?>
