<?php
declare(strict_types=1);

/**
 * Activity Item Partial
 *
 * Renders a single activity log item for the admin dashboard.
 */

/**
 * Render an activity item
 *
 * @param array $activity Activity data from audit_log
 * @return string Rendered HTML
 */
function renderActivityItem(array $activity): string
{
    $icon = AuditLog::getActionIcon($activity['action']);
    $description = AuditLog::getActionDescription($activity['action'], $activity['details'] ?? []);
    $memberName = e($activity['member_name'] ?? 'System');
    $time = formatRelativeTime($activity['created_at']);

    return <<<HTML
    <div class="activity-item">
        <div class="activity-icon">{$icon}</div>
        <div class="activity-content">
            <div class="activity-description">{$description}</div>
            <div class="activity-meta">
                <span class="activity-member">{$memberName}</span>
                <span class="activity-time">{$time}</span>
            </div>
        </div>
    </div>
    HTML;
}

/**
 * Format a timestamp as relative time
 *
 * @param string $datetime DateTime string
 * @return string Relative time string
 */
function formatRelativeTime(string $datetime): string
{
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $timestamp);
    }
}
?>

<style>
.activity-item {
    display: flex;
    gap: 1rem;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border-color, #e0e0e0);
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-icon {
    font-size: 1.25rem;
    opacity: 0.7;
    flex-shrink: 0;
}

.activity-content {
    flex: 1;
    min-width: 0;
}

.activity-description {
    font-size: 0.9375rem;
    line-height: 1.4;
}

.activity-meta {
    display: flex;
    gap: 0.5rem;
    font-size: 0.8125rem;
    color: var(--text-secondary);
    margin-top: 0.25rem;
}

.activity-member::after {
    content: '\00B7';
    margin-left: 0.5rem;
}
</style>
