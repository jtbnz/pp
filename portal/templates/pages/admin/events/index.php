<?php
declare(strict_types=1);

/**
 * Admin Events List Template
 *
 * Event list with date range filter and management actions.
 */

global $config;

$pageTitle = $pageTitle ?? 'Manage Events';
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

<div class="page-admin-events">
    <header class="page-header">
        <div class="header-row">
            <div>
                <h1>Events</h1>
                <p class="text-secondary"><?= count($events) ?> event<?= count($events) !== 1 ? 's' : '' ?></p>
            </div>
            <a href="/admin/events/create" class="btn btn-primary">
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

    <!-- Date Range Filter -->
    <section class="filters-section mb-3">
        <form method="GET" action="/admin/events" class="filters-form">
            <div class="filter-group">
                <label for="from" class="form-label-inline">From:</label>
                <input type="date" id="from" name="from" class="form-input"
                       value="<?= e($from) ?>">
            </div>
            <div class="filter-group">
                <label for="to" class="form-label-inline">To:</label>
                <input type="date" id="to" name="to" class="form-input"
                       value="<?= e($to) ?>">
            </div>
            <button type="submit" class="btn btn-secondary">Filter</button>
        </form>
    </section>

    <!-- Events List -->
    <section class="events-list">
        <?php if (empty($events)): ?>
        <div class="card">
            <div class="card-body text-center p-4">
                <p class="text-secondary">No events in this date range</p>
                <a href="/admin/events/create" class="btn btn-primary mt-2">Create Event</a>
            </div>
        </div>
        <?php else: ?>
        <div class="events-timeline">
            <?php
            $currentMonth = '';
            foreach ($events as $event):
                $eventDate = new DateTime($event['start_time']);
                $monthYear = $eventDate->format('F Y');

                if ($monthYear !== $currentMonth):
                    $currentMonth = $monthYear;
            ?>
            <div class="timeline-month-header"><?= e($monthYear) ?></div>
            <?php endif; ?>

            <div class="event-card card">
                <div class="event-date-badge">
                    <span class="day"><?= $eventDate->format('d') ?></span>
                    <span class="weekday"><?= $eventDate->format('D') ?></span>
                </div>
                <div class="event-content">
                    <div class="event-header">
                        <h3 class="event-title"><?= e($event['title']) ?></h3>
                        <div class="event-badges">
                            <?php if ($event['is_training']): ?>
                            <span class="badge badge-training">Training</span>
                            <?php endif; ?>
                            <?php if ($event['all_day']): ?>
                            <span class="badge">All Day</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="event-meta">
                        <?php if (!$event['all_day']): ?>
                        <span class="event-time">
                            <?= $eventDate->format('g:i A') ?>
                            <?php if ($event['end_time']): ?>
                            - <?= (new DateTime($event['end_time']))->format('g:i A') ?>
                            <?php endif; ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($event['location']): ?>
                        <span class="event-location"><?= e($event['location']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($event['description']): ?>
                    <p class="event-description"><?= e($event['description']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="event-actions">
                    <a href="/api/events/<?= $event['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <div class="admin-nav-back mt-4">
        <a href="/admin" class="btn btn-text">&larr; Back to Dashboard</a>
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

.filter-group .form-input {
    width: auto;
}

.timeline-month-header {
    font-weight: 600;
    font-size: 0.875rem;
    text-transform: uppercase;
    color: var(--text-secondary);
    padding: 1rem 0 0.5rem;
    border-bottom: 1px solid var(--border, #e0e0e0);
    margin-bottom: 0.5rem;
}

.event-card {
    display: flex;
    gap: 1rem;
    padding: 1rem;
    margin-bottom: 0.5rem;
}

.event-date-badge {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 50px;
    padding: 0.5rem;
    background: var(--bg-secondary, #f5f5f5);
    border-radius: 8px;
}

.event-date-badge .day {
    font-size: 1.5rem;
    font-weight: 700;
    line-height: 1;
}

.event-date-badge .weekday {
    font-size: 0.75rem;
    text-transform: uppercase;
    color: var(--text-secondary);
}

.event-content {
    flex: 1;
}

.event-header {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.event-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
}

.event-badges {
    display: flex;
    gap: 0.25rem;
}

.badge {
    display: inline-block;
    padding: 0.125rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 4px;
    background: var(--bg-secondary, #e0e0e0);
}

.badge-training {
    background: var(--primary, #D32F2F);
    color: white;
}

.event-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    font-size: 0.875rem;
    color: var(--text-secondary);
    margin-top: 0.25rem;
}

.event-time::before {
    content: '\1F551 ';
}

.event-location::before {
    content: '\1F4CD ';
}

.event-description {
    font-size: 0.875rem;
    color: var(--text-secondary);
    margin: 0.5rem 0 0 0;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.event-actions {
    display: flex;
    align-items: flex-start;
}

@media (max-width: 600px) {
    .event-card {
        flex-direction: column;
    }

    .event-date-badge {
        flex-direction: row;
        gap: 0.5rem;
        width: fit-content;
    }

    .event-actions {
        margin-top: 0.5rem;
    }
}
</style>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../../layouts/main.php';
