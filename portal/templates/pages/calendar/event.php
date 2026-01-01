<?php
declare(strict_types=1);

/**
 * Single Event View Template
 *
 * Displays details of a single calendar event.
 */

global $config;

$pageTitle = $pageTitle ?? 'Event';
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';
$user = currentUser();
$isAdmin = $isAdmin ?? false;
$event = $event ?? [];

// Get flash message if any
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Format dates
$startDate = !empty($event['start_time']) ? new DateTime($event['start_time'], new DateTimeZone('Pacific/Auckland')) : null;
$endDate = !empty($event['end_time']) ? new DateTime($event['end_time'], new DateTimeZone('Pacific/Auckland')) : null;

// Start output buffering for content
ob_start();
?>

<div class="page-event">
    <?php if ($flash): ?>
        <div class="flash-message flash-<?= e($flash['type']) ?>">
            <?= e($flash['message']) ?>
            <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Back link -->
    <a href="<?= url('/calendar') ?>" class="back-link">&larr; Back to Calendar</a>

    <div class="event-card">
        <!-- Event Header -->
        <div class="event-header <?= !empty($event['is_training']) ? 'training' : '' ?>">
            <?php if (!empty($event['is_training'])): ?>
                <span class="event-badge">Training Night</span>
            <?php endif; ?>
            <h1 class="event-title"><?= e($event['title'] ?? 'Event') ?></h1>
        </div>

        <!-- Event Details -->
        <div class="event-body">
            <!-- Date & Time -->
            <div class="event-detail">
                <span class="detail-icon">&#128197;</span>
                <div class="detail-content">
                    <span class="detail-label">Date & Time</span>
                    <?php if (!empty($event['all_day'])): ?>
                        <span class="detail-value">
                            <?= $startDate ? $startDate->format('l, j F Y') : 'No date' ?>
                            (All Day)
                        </span>
                    <?php else: ?>
                        <span class="detail-value">
                            <?= $startDate ? $startDate->format('l, j F Y') : 'No date' ?>
                        </span>
                        <span class="detail-subvalue">
                            <?= $startDate ? $startDate->format('g:i A') : '' ?>
                            <?php if ($endDate): ?>
                                - <?= $endDate->format('g:i A') ?>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Location -->
            <?php if (!empty($event['location'])): ?>
                <div class="event-detail">
                    <span class="detail-icon">&#128205;</span>
                    <div class="detail-content">
                        <span class="detail-label">Location</span>
                        <span class="detail-value"><?= e($event['location']) ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Description -->
            <?php if (!empty($event['description'])): ?>
                <div class="event-detail">
                    <span class="detail-icon">&#128196;</span>
                    <div class="detail-content">
                        <span class="detail-label">Description</span>
                        <div class="detail-value description"><?= nl2br(e($event['description'])) ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Recurrence -->
            <?php if (!empty($event['recurrence_rule'])): ?>
                <div class="event-detail">
                    <span class="detail-icon">&#128260;</span>
                    <div class="detail-content">
                        <span class="detail-label">Repeats</span>
                        <span class="detail-value"><?= e($this->formatRecurrence($event['recurrence_rule'] ?? '')) ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Created by -->
            <?php if (!empty($event['creator_name'])): ?>
                <div class="event-detail">
                    <span class="detail-icon">&#128100;</span>
                    <div class="detail-content">
                        <span class="detail-label">Created by</span>
                        <span class="detail-value"><?= e($event['creator_name']) ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Event Actions -->
        <div class="event-actions">
            <a href="<?= url('/calendar/' . (int)$event['id'] . '/ics') ?>" class="btn btn-secondary">
                <span>&#128197;</span> Add to Calendar
            </a>

            <?php if ($isAdmin): ?>
                <a href="<?= url('/calendar/' . (int)$event['id'] . '/edit') ?>" class="btn">
                    <span>&#9998;</span> Edit
                </a>

                <form action="<?= url('/calendar/' . (int)$event['id']) ?>" method="POST" class="delete-form"
                      onsubmit="return confirm('Are you sure you want to delete this event?');">
                    <input type="hidden" name="_method" value="DELETE">
                    <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                    <button type="submit" class="btn btn-outline-danger">
                        <span>&#128465;</span> Delete
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($event['is_training'])): ?>
        <!-- Training-specific info -->
        <div class="training-info card mt-4">
            <div class="card-header">
                <h2 class="card-title">Training Night Info</h2>
            </div>
            <div class="card-body">
                <p>Training nights are held every Monday at 7:00 PM at the Puke Fire Station.</p>
                <p>If you cannot attend, please submit a leave request.</p>
                <a href="<?= url('/leave') ?>" class="btn btn-outline mt-3">Request Leave</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.page-event {
    padding: var(--spacing-md);
    max-width: 800px;
    margin: 0 auto;
}

.back-link {
    display: inline-block;
    margin-bottom: var(--spacing-md);
    color: var(--color-text-secondary);
    text-decoration: none;
}

.back-link:hover {
    color: var(--color-accent);
}

.event-card {
    background: var(--color-surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    overflow: hidden;
}

.event-header {
    padding: var(--spacing-lg);
    background: var(--color-accent);
    color: var(--color-text-inverse);
}

.event-header.training {
    background: var(--color-primary);
}

.event-badge {
    display: inline-block;
    padding: var(--spacing-xs) var(--spacing-sm);
    background: rgba(255, 255, 255, 0.2);
    border-radius: var(--radius-sm);
    font-size: var(--font-size-xs);
    font-weight: var(--font-weight-medium);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: var(--spacing-sm);
}

.event-header .event-title {
    margin: 0;
    font-size: var(--font-size-2xl);
}

.event-body {
    padding: var(--spacing-lg);
}

.event-detail {
    display: flex;
    gap: var(--spacing-md);
    padding: var(--spacing-md) 0;
    border-bottom: 1px solid var(--color-divider);
}

.event-detail:last-child {
    border-bottom: none;
}

.detail-icon {
    font-size: var(--font-size-xl);
    width: 32px;
    text-align: center;
    flex-shrink: 0;
}

.detail-content {
    flex: 1;
}

.detail-label {
    display: block;
    font-size: var(--font-size-xs);
    color: var(--color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: var(--spacing-xs);
}

.detail-value {
    display: block;
    font-size: var(--font-size-base);
    font-weight: var(--font-weight-medium);
}

.detail-value.description {
    font-weight: var(--font-weight-normal);
    line-height: var(--line-height-relaxed);
}

.detail-subvalue {
    display: block;
    font-size: var(--font-size-sm);
    color: var(--color-text-secondary);
}

.event-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-sm);
    padding: var(--spacing-lg);
    background: var(--color-background);
    border-top: 1px solid var(--color-divider);
}

.delete-form {
    margin: 0;
}

.btn-outline-danger {
    background: transparent;
    border-color: var(--color-error);
    color: var(--color-error);
}

.btn-outline-danger:hover {
    background: var(--color-error);
    color: var(--color-text-inverse);
}

.training-info {
    margin-top: var(--spacing-lg);
}
</style>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../layouts/main.php';

/**
 * Format recurrence rule for display
 */
function formatRecurrence(string $rule): string
{
    $rule = preg_replace('/^RRULE:/i', '', $rule);
    $parts = explode(';', $rule);
    $parsed = [];

    foreach ($parts as $part) {
        if (str_contains($part, '=')) {
            [$key, $value] = explode('=', $part, 2);
            $parsed[strtoupper($key)] = $value;
        }
    }

    $freq = $parsed['FREQ'] ?? '';
    $interval = (int)($parsed['INTERVAL'] ?? 1);
    $byDay = $parsed['BYDAY'] ?? '';

    $dayNames = [
        'MO' => 'Monday', 'TU' => 'Tuesday', 'WE' => 'Wednesday',
        'TH' => 'Thursday', 'FR' => 'Friday', 'SA' => 'Saturday', 'SU' => 'Sunday'
    ];

    $text = '';

    switch ($freq) {
        case 'DAILY':
            $text = $interval === 1 ? 'Every day' : "Every {$interval} days";
            break;
        case 'WEEKLY':
            if ($byDay) {
                $days = array_map(fn($d) => $dayNames[$d] ?? $d, explode(',', $byDay));
                $text = 'Every ' . implode(', ', $days);
            } else {
                $text = $interval === 1 ? 'Every week' : "Every {$interval} weeks";
            }
            break;
        case 'MONTHLY':
            $text = $interval === 1 ? 'Every month' : "Every {$interval} months";
            break;
        case 'YEARLY':
            $text = $interval === 1 ? 'Every year' : "Every {$interval} years";
            break;
        default:
            $text = 'Custom recurrence';
    }

    return $text;
}
