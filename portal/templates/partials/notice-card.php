<?php
declare(strict_types=1);

/**
 * Notice Card Partial
 *
 * Reusable notice card component with different styling for each type.
 *
 * Variables:
 * - $notice: array - The notice data
 * - $showActions: bool - Whether to show edit/delete actions (default: false)
 * - $linkToDetail: bool - Whether to link to detail page (default: true)
 */

require_once __DIR__ . '/../../src/Helpers/Markdown.php';

$showActions = $showActions ?? false;
$linkToDetail = $linkToDetail ?? true;

// Calculate remaining time for timed notices
$remainingSeconds = null;
if ($notice['type'] === 'timed' && !empty($notice['display_to'])) {
    $expiresAt = strtotime($notice['display_to']);
    $remaining = $expiresAt - time();
    $remainingSeconds = $remaining > 0 ? $remaining : null;
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

// Build card classes
$cardClasses = ['notice-card', 'notice-type-' . e($notice['type'])];
if (!$isActive) {
    $cardClasses[] = 'notice-inactive';
}
?>

<article class="<?= implode(' ', $cardClasses) ?>" data-notice-id="<?= (int)$notice['id'] ?>" <?php if ($remainingSeconds): ?>data-expires-in="<?= $remainingSeconds ?>"<?php endif; ?>>
    <div class="notice-card-header">
        <div class="notice-card-meta">
            <?php if ($notice['type'] === 'sticky'): ?>
                <span class="notice-badge notice-badge-sticky" title="Pinned notice">
                    <span class="badge-icon">&#128204;</span>
                    Pinned
                </span>
            <?php elseif ($notice['type'] === 'urgent'): ?>
                <span class="notice-badge notice-badge-urgent" title="Urgent notice">
                    <span class="badge-icon">&#9888;</span>
                    Urgent
                </span>
            <?php elseif ($notice['type'] === 'timed' && $remainingSeconds): ?>
                <span class="notice-badge notice-badge-timed" title="Timed notice">
                    <span class="badge-icon">&#9201;</span>
                    <span class="notice-countdown" data-seconds="<?= $remainingSeconds ?>">
                        <?= formatCountdown($remainingSeconds) ?>
                    </span>
                </span>
            <?php endif; ?>

            <?php if (!$isActive): ?>
                <span class="notice-badge notice-badge-inactive">
                    Inactive
                </span>
            <?php endif; ?>
        </div>

        <?php if ($showActions && hasRole('admin')): ?>
            <div class="notice-card-actions">
                <a href="/notices/<?= (int)$notice['id'] ?>/edit" class="btn btn-sm" title="Edit">
                    &#9998;
                </a>
                <form action="/notices/<?= (int)$notice['id'] ?>" method="POST" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this notice?')">
                    <input type="hidden" name="_method" value="DELETE">
                    <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                    <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                        &#128465;
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="notice-card-body">
        <?php if ($linkToDetail): ?>
            <h3 class="notice-card-title">
                <a href="/notices/<?= (int)$notice['id'] ?>"><?= e($notice['title']) ?></a>
            </h3>
        <?php else: ?>
            <h3 class="notice-card-title"><?= e($notice['title']) ?></h3>
        <?php endif; ?>

        <?php if (!empty($notice['content'])): ?>
            <div class="notice-card-excerpt">
                <?= e(Markdown::truncate($notice['content'], 150)) ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="notice-card-footer">
        <span class="notice-author">
            <?php if (!empty($notice['author_name'])): ?>
                By <?= e($notice['author_name']) ?>
            <?php endif; ?>
        </span>
        <span class="notice-date" title="<?= e($notice['created_at']) ?>">
            <?= timeAgo($notice['created_at']) ?>
        </span>
    </div>
</article>

<?php
/**
 * Format countdown time
 */
function formatCountdown(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . 's';
    }

    if ($seconds < 3600) {
        $minutes = (int)floor($seconds / 60);
        return $minutes . 'm';
    }

    if ($seconds < 86400) {
        $hours = (int)floor($seconds / 3600);
        $minutes = (int)floor(($seconds % 3600) / 60);
        return $hours . 'h ' . $minutes . 'm';
    }

    $days = (int)floor($seconds / 86400);
    $hours = (int)floor(($seconds % 86400) / 3600);
    return $days . 'd ' . $hours . 'h';
}
?>
