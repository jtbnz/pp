<?php
declare(strict_types=1);

/**
 * Calendar Index Template
 *
 * Displays the calendar with day/week/month view switcher.
 */

global $config;

$pageTitle = $pageTitle ?? 'Calendar';
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';
$user = currentUser();
$isAdmin = $isAdmin ?? false;

// Calendar variables
$view = $view ?? 'month';
$currentDate = $currentDate ?? date('Y-m-d');
$dateRange = $dateRange ?? ['from' => date('Y-m-01'), 'to' => date('Y-m-t')];
$events = $events ?? [];
$upcomingTrainings = $upcomingTrainings ?? [];

// Parse current date
$currentDateTime = new DateTime($currentDate, new DateTimeZone('Pacific/Auckland'));
$currentMonth = $currentDateTime->format('F Y');
$currentWeek = 'Week ' . $currentDateTime->format('W, Y');
$currentDay = $currentDateTime->format('l, j F Y');

// Get flash message if any
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Generate calendar grid for month view
$calendarDays = [];
if ($view === 'month') {
    $gridStart = new DateTime($dateRange['from'], new DateTimeZone('Pacific/Auckland'));
    $gridEnd = new DateTime($dateRange['to'], new DateTimeZone('Pacific/Auckland'));
    $gridEnd->modify('+1 day');

    $today = date('Y-m-d');
    $currentMonthNum = $currentDateTime->format('m');

    $eventsByDate = [];
    foreach ($events as $event) {
        $eventDate = date('Y-m-d', strtotime($event['start_time']));
        if (!isset($eventsByDate[$eventDate])) {
            $eventsByDate[$eventDate] = [];
        }
        $eventsByDate[$eventDate][] = $event;
    }

    $weekDays = [];
    while ($gridStart < $gridEnd) {
        $dateStr = $gridStart->format('Y-m-d');
        $dayEvents = $eventsByDate[$dateStr] ?? [];

        $weekDays[] = [
            'date' => $dateStr,
            'day' => $gridStart->format('j'),
            'isToday' => $dateStr === $today,
            'isCurrentMonth' => $gridStart->format('m') === $currentMonthNum,
            'events' => $dayEvents,
            'dayOfWeek' => $gridStart->format('N'),
        ];

        if (count($weekDays) === 7) {
            $calendarDays[] = $weekDays;
            $weekDays = [];
        }

        $gridStart->modify('+1 day');
    }
}

// Start output buffering for content
ob_start();
?>

<div class="page-calendar">
    <?php if ($flash): ?>
        <div class="flash-message flash-<?= e($flash['type']) ?>">
            <?= e($flash['message']) ?>
            <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Calendar Header -->
    <div class="calendar-header">
        <div class="calendar-nav">
            <button type="button" class="btn btn-sm calendar-nav-btn" id="calendar-prev" aria-label="Previous">
                <span>&larr;</span>
            </button>
            <button type="button" class="btn btn-sm calendar-nav-btn" id="calendar-today">Today</button>
            <button type="button" class="btn btn-sm calendar-nav-btn" id="calendar-next" aria-label="Next">
                <span>&rarr;</span>
            </button>
        </div>

        <h1 class="calendar-title" id="calendar-title">
            <?php if ($view === 'day'): ?>
                <?= e($currentDay) ?>
            <?php elseif ($view === 'week'): ?>
                <?= e($currentWeek) ?>
            <?php else: ?>
                <?= e($currentMonth) ?>
            <?php endif; ?>
        </h1>

        <div class="calendar-view-switcher">
            <button type="button"
                    class="btn btn-sm <?= $view === 'day' ? 'btn-primary' : '' ?>"
                    data-view="day">Day</button>
            <button type="button"
                    class="btn btn-sm <?= $view === 'week' ? 'btn-primary' : '' ?>"
                    data-view="week">Week</button>
            <button type="button"
                    class="btn btn-sm <?= $view === 'month' ? 'btn-primary' : '' ?>"
                    data-view="month">Month</button>
        </div>
    </div>

    <!-- Calendar Grid -->
    <div class="calendar-container">
        <?php if ($view === 'month'): ?>
            <!-- Month View -->
            <div class="calendar-grid month-view" id="calendar-grid">
                <!-- Day headers -->
                <div class="calendar-weekdays">
                    <div class="calendar-weekday">Mon</div>
                    <div class="calendar-weekday">Tue</div>
                    <div class="calendar-weekday">Wed</div>
                    <div class="calendar-weekday">Thu</div>
                    <div class="calendar-weekday">Fri</div>
                    <div class="calendar-weekday weekend">Sat</div>
                    <div class="calendar-weekday weekend">Sun</div>
                </div>

                <!-- Calendar weeks -->
                <div class="calendar-weeks">
                    <?php foreach ($calendarDays as $week): ?>
                        <div class="calendar-week">
                            <?php foreach ($week as $day): ?>
                                <div class="calendar-day <?= $day['isToday'] ? 'today' : '' ?> <?= !$day['isCurrentMonth'] ? 'other-month' : '' ?> <?= $day['dayOfWeek'] >= 6 ? 'weekend' : '' ?>"
                                     data-date="<?= e($day['date']) ?>">
                                    <span class="day-number"><?= e($day['day']) ?></span>
                                    <?php if (!empty($day['events'])): ?>
                                        <div class="day-events">
                                            <?php foreach (array_slice($day['events'], 0, 3) as $event): ?>
                                                <a href="<?= url('/calendar/' . (int)$event['id']) ?>"
                                                   class="day-event <?= $event['is_training'] ? 'training' : '' ?>"
                                                   title="<?= e($event['title']) ?>">
                                                    <?php if (!$event['all_day']): ?>
                                                        <span class="event-time"><?= date('H:i', strtotime($event['start_time'])) ?></span>
                                                    <?php endif; ?>
                                                    <span class="event-title"><?= e($event['title']) ?></span>
                                                </a>
                                            <?php endforeach; ?>
                                            <?php if (count($day['events']) > 3): ?>
                                                <span class="day-more">+<?= count($day['events']) - 3 ?> more</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php elseif ($view === 'week'): ?>
            <!-- Week View -->
            <div class="calendar-grid week-view" id="calendar-grid">
                <div class="week-header">
                    <?php
                    $weekStart = new DateTime($dateRange['from'], new DateTimeZone('Pacific/Auckland'));
                    for ($i = 0; $i < 7; $i++):
                        $dayDate = $weekStart->format('Y-m-d');
                        $isToday = $dayDate === date('Y-m-d');
                    ?>
                        <div class="week-day-header <?= $isToday ? 'today' : '' ?>">
                            <span class="week-day-name"><?= $weekStart->format('D') ?></span>
                            <span class="week-day-number"><?= $weekStart->format('j') ?></span>
                        </div>
                    <?php
                        $weekStart->modify('+1 day');
                    endfor;
                    ?>
                </div>
                <div class="week-body">
                    <?php
                    // Group events by date
                    $eventsByDate = [];
                    foreach ($events as $event) {
                        $eventDate = date('Y-m-d', strtotime($event['start_time']));
                        if (!isset($eventsByDate[$eventDate])) {
                            $eventsByDate[$eventDate] = [];
                        }
                        $eventsByDate[$eventDate][] = $event;
                    }

                    $weekStart = new DateTime($dateRange['from'], new DateTimeZone('Pacific/Auckland'));
                    for ($i = 0; $i < 7; $i++):
                        $dayDate = $weekStart->format('Y-m-d');
                        $dayEvents = $eventsByDate[$dayDate] ?? [];
                        $isToday = $dayDate === date('Y-m-d');
                    ?>
                        <div class="week-day-column <?= $isToday ? 'today' : '' ?>" data-date="<?= e($dayDate) ?>">
                            <?php foreach ($dayEvents as $event): ?>
                                <a href="<?= url('/calendar/' . (int)$event['id']) ?>"
                                   class="week-event <?= $event['is_training'] ? 'training' : '' ?>">
                                    <?php if (!$event['all_day']): ?>
                                        <span class="event-time"><?= date('H:i', strtotime($event['start_time'])) ?></span>
                                    <?php endif; ?>
                                    <span class="event-title"><?= e($event['title']) ?></span>
                                    <?php if ($event['location']): ?>
                                        <span class="event-location"><?= e($event['location']) ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php
                        $weekStart->modify('+1 day');
                    endfor;
                    ?>
                </div>
            </div>

        <?php else: ?>
            <!-- Day View -->
            <div class="calendar-grid day-view" id="calendar-grid">
                <div class="day-events-list">
                    <?php if (empty($events)): ?>
                        <p class="no-events">No events scheduled for this day.</p>
                    <?php else: ?>
                        <?php foreach ($events as $event): ?>
                            <a href="<?= url('/calendar/' . (int)$event['id']) ?>"
                               class="day-event-card <?= $event['is_training'] ? 'training' : '' ?>">
                                <div class="event-time-block">
                                    <?php if ($event['all_day']): ?>
                                        <span class="all-day-badge">All Day</span>
                                    <?php else: ?>
                                        <span class="event-start"><?= date('H:i', strtotime($event['start_time'])) ?></span>
                                        <?php if ($event['end_time']): ?>
                                            <span class="event-end"><?= date('H:i', strtotime($event['end_time'])) ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="event-details">
                                    <h3 class="event-title"><?= e($event['title']) ?></h3>
                                    <?php if ($event['location']): ?>
                                        <p class="event-location"><?= e($event['location']) ?></p>
                                    <?php endif; ?>
                                    <?php if ($event['description']): ?>
                                        <p class="event-description"><?= e(substr($event['description'], 0, 100)) ?><?= strlen($event['description']) > 100 ? '...' : '' ?></p>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Upcoming Trainings Sidebar (visible on desktop) -->
    <?php if (!empty($upcomingTrainings)): ?>
        <aside class="calendar-sidebar">
            <h2>Upcoming Trainings</h2>
            <div class="upcoming-list">
                <?php foreach ($upcomingTrainings as $training): ?>
                    <a href="<?= url('/calendar/' . (int)$training['id']) ?>" class="upcoming-item">
                        <span class="upcoming-date"><?= date('D, j M', strtotime($training['start_time'])) ?></span>
                        <span class="upcoming-time"><?= date('H:i', strtotime($training['start_time'])) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <a href="<?= url('/calendar/trainings') ?>" class="btn btn-sm btn-outline mt-3">View All Trainings</a>
        </aside>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
        <!-- Admin Actions -->
        <div class="calendar-actions">
            <a href="<?= url('/calendar/create') ?>" class="btn btn-primary">
                <span>+</span> New Event
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Calendar data for JavaScript -->
<script>
    window.calendarConfig = {
        view: '<?= e($view) ?>',
        currentDate: '<?= e($currentDate) ?>',
        dateRange: {
            from: '<?= e($dateRange['from']) ?>',
            to: '<?= e($dateRange['to']) ?>'
        },
        eventsUrl: '<?= url('/api/events') ?>',
        isAdmin: <?= $isAdmin ? 'true' : 'false' ?>
    };
</script>

<style>
/* Calendar-specific styles */
.page-calendar {
    padding: var(--spacing-md);
    position: relative;
}

.calendar-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-lg);
}

.calendar-nav {
    display: flex;
    gap: var(--spacing-xs);
}

.calendar-nav-btn {
    min-width: 40px;
}

.calendar-title {
    font-size: var(--font-size-xl);
    font-weight: var(--font-weight-semibold);
    margin: 0;
    text-align: center;
    flex: 1;
}

.calendar-view-switcher {
    display: flex;
    gap: var(--spacing-xs);
}

.calendar-container {
    background: var(--color-surface);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

/* Month View */
.calendar-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    background: var(--color-background);
    border-bottom: 1px solid var(--color-border);
}

.calendar-weekday {
    padding: var(--spacing-sm);
    text-align: center;
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-medium);
    color: var(--color-text-secondary);
}

.calendar-weekday.weekend {
    color: var(--color-error);
}

.calendar-weeks {
    display: flex;
    flex-direction: column;
}

.calendar-week {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    min-height: 80px;
    border-bottom: 1px solid var(--color-divider);
}

.calendar-week:last-child {
    border-bottom: none;
}

.calendar-day {
    padding: var(--spacing-xs);
    border-right: 1px solid var(--color-divider);
    min-height: 80px;
    position: relative;
    cursor: pointer;
    transition: background var(--transition-fast);
}

.calendar-day:last-child {
    border-right: none;
}

.calendar-day:hover {
    background: var(--color-background);
}

.calendar-day.today {
    background: rgba(var(--color-primary-rgb), 0.1);
}

.calendar-day.today .day-number {
    background: var(--color-primary);
    color: var(--color-text-inverse);
    border-radius: var(--radius-full);
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.calendar-day.other-month {
    background: var(--color-background);
    opacity: 0.6;
}

.calendar-day.weekend {
    background: rgba(var(--color-primary-rgb), 0.02);
}

.day-number {
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-medium);
    display: inline-block;
    margin-bottom: var(--spacing-xs);
}

.day-events {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.day-event {
    display: block;
    padding: 2px 4px;
    background: var(--color-accent);
    color: var(--color-text-inverse);
    font-size: var(--font-size-xs);
    border-radius: 2px;
    text-decoration: none;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.day-event.training {
    background: var(--color-primary);
}

.day-event .event-time {
    font-weight: var(--font-weight-medium);
    margin-right: 4px;
}

.day-more {
    font-size: var(--font-size-xs);
    color: var(--color-text-secondary);
    padding: 2px 4px;
}

/* Week View */
.week-view {
    display: flex;
    flex-direction: column;
}

.week-header {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    background: var(--color-background);
    border-bottom: 1px solid var(--color-border);
}

.week-day-header {
    padding: var(--spacing-sm);
    text-align: center;
    border-right: 1px solid var(--color-divider);
}

.week-day-header:last-child {
    border-right: none;
}

.week-day-header.today {
    background: rgba(var(--color-primary-rgb), 0.1);
}

.week-day-name {
    display: block;
    font-size: var(--font-size-sm);
    color: var(--color-text-secondary);
}

.week-day-number {
    display: block;
    font-size: var(--font-size-xl);
    font-weight: var(--font-weight-semibold);
}

.week-body {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    min-height: 400px;
}

.week-day-column {
    padding: var(--spacing-sm);
    border-right: 1px solid var(--color-divider);
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
}

.week-day-column:last-child {
    border-right: none;
}

.week-day-column.today {
    background: rgba(var(--color-primary-rgb), 0.05);
}

.week-event {
    display: block;
    padding: var(--spacing-sm);
    background: var(--color-accent);
    color: var(--color-text-inverse);
    border-radius: var(--radius-sm);
    text-decoration: none;
    font-size: var(--font-size-sm);
}

.week-event.training {
    background: var(--color-primary);
}

.week-event .event-time {
    display: block;
    font-weight: var(--font-weight-medium);
    font-size: var(--font-size-xs);
}

.week-event .event-title {
    display: block;
    font-weight: var(--font-weight-medium);
}

.week-event .event-location {
    display: block;
    font-size: var(--font-size-xs);
    opacity: 0.9;
}

/* Day View */
.day-view {
    padding: var(--spacing-md);
}

.day-events-list {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-md);
}

.no-events {
    text-align: center;
    color: var(--color-text-secondary);
    padding: var(--spacing-xl);
}

.day-event-card {
    display: flex;
    gap: var(--spacing-md);
    padding: var(--spacing-md);
    background: var(--color-background);
    border-radius: var(--radius-md);
    text-decoration: none;
    color: var(--color-text);
    border-left: 4px solid var(--color-accent);
    transition: transform var(--transition-fast);
}

.day-event-card.training {
    border-left-color: var(--color-primary);
}

.day-event-card:hover {
    transform: translateX(4px);
}

.event-time-block {
    min-width: 60px;
    text-align: center;
}

.event-time-block .event-start {
    display: block;
    font-size: var(--font-size-lg);
    font-weight: var(--font-weight-semibold);
}

.event-time-block .event-end {
    display: block;
    font-size: var(--font-size-sm);
    color: var(--color-text-secondary);
}

.event-time-block .all-day-badge {
    display: inline-block;
    padding: var(--spacing-xs) var(--spacing-sm);
    background: var(--color-accent);
    color: var(--color-text-inverse);
    border-radius: var(--radius-sm);
    font-size: var(--font-size-xs);
}

.event-details .event-title {
    margin: 0 0 var(--spacing-xs);
    font-size: var(--font-size-lg);
}

.event-details .event-location {
    margin: 0;
    color: var(--color-text-secondary);
    font-size: var(--font-size-sm);
}

.event-details .event-description {
    margin: var(--spacing-sm) 0 0;
    color: var(--color-text-secondary);
    font-size: var(--font-size-sm);
}

/* Calendar Sidebar */
.calendar-sidebar {
    display: none;
}

@media (min-width: 1024px) {
    .page-calendar {
        display: grid;
        grid-template-columns: 1fr 280px;
        gap: var(--spacing-lg);
    }

    .calendar-header {
        grid-column: 1 / -1;
    }

    .calendar-sidebar {
        display: block;
    }

    .calendar-sidebar h2 {
        font-size: var(--font-size-lg);
        margin-bottom: var(--spacing-md);
    }

    .upcoming-list {
        display: flex;
        flex-direction: column;
        gap: var(--spacing-sm);
    }

    .upcoming-item {
        display: flex;
        justify-content: space-between;
        padding: var(--spacing-sm);
        background: var(--color-surface);
        border-radius: var(--radius-md);
        text-decoration: none;
        color: var(--color-text);
        box-shadow: var(--shadow-sm);
    }

    .upcoming-item:hover {
        background: var(--color-background);
    }

    .upcoming-date {
        font-weight: var(--font-weight-medium);
    }

    .upcoming-time {
        color: var(--color-text-secondary);
    }
}

/* Admin Actions */
.calendar-actions {
    position: fixed;
    bottom: calc(var(--bottom-nav-height) + var(--spacing-md));
    right: var(--spacing-md);
    z-index: var(--z-sticky);
}

@media (min-width: 768px) {
    .calendar-actions {
        bottom: var(--spacing-md);
    }
}

.calendar-actions .btn {
    border-radius: var(--radius-full);
    box-shadow: var(--shadow-lg);
    padding: var(--spacing-md) var(--spacing-lg);
}

/* Mobile adjustments */
@media (max-width: 640px) {
    .calendar-header {
        flex-direction: column;
        align-items: stretch;
    }

    .calendar-nav {
        order: 2;
        justify-content: center;
    }

    .calendar-title {
        order: 1;
        text-align: center;
    }

    .calendar-view-switcher {
        order: 3;
        justify-content: center;
    }

    .calendar-day {
        min-height: 60px;
        padding: 2px;
    }

    .day-number {
        font-size: var(--font-size-xs);
    }

    .day-event {
        font-size: 10px;
        padding: 1px 2px;
    }

    .day-event .event-time {
        display: none;
    }

    .week-body {
        min-height: 300px;
    }

    .week-event {
        padding: var(--spacing-xs);
        font-size: var(--font-size-xs);
    }
}
</style>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../layouts/main.php';
