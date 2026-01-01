<?php
declare(strict_types=1);

/**
 * Home Page Template
 *
 * Landing page for the Puke Portal application.
 *
 * Variables:
 * - $nextTraining: array|null - Next training event
 * - $upcomingEvents: array - Upcoming calendar events
 * - $recentNotices: array - Recent notices
 * - $pendingLeave: array - User's pending leave requests
 */

global $config;

$pageTitle = 'Home';
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';
$user = currentUser();

// Ensure variables are set
$nextTraining = $nextTraining ?? null;
$upcomingEvents = $upcomingEvents ?? [];
$recentNotices = $recentNotices ?? [];
$pendingLeave = $pendingLeave ?? [];

// Start output buffering for content
ob_start();
?>

<div class="page-home">
    <?php if ($user): ?>
        <!-- Authenticated User View -->
        <section class="welcome-section">
            <h1>Welcome, <?= e($user['name']) ?></h1>
            <p class="text-secondary">Puke Volunteer Fire Brigade Portal</p>
        </section>

        <!-- Next Training Card -->
        <?php if ($nextTraining): ?>
            <?php
            $trainingDate = new DateTime($nextTraining['start_time'], new DateTimeZone('Pacific/Auckland'));
            $trainingDateStr = $trainingDate->format('Y-m-d');
            $trainingId = $nextTraining['id'] ?? null;
            ?>
            <section class="next-training-card">
                <span class="next-training-label">Next Training</span>
                <p class="next-training-date"><?= $trainingDate->format('l, j F Y') ?></p>
                <p class="next-training-time"><?= $trainingDate->format('g:i A') ?></p>
                <div class="next-training-actions">
                    <a href="<?= url('/leave') ?>" class="btn" style="background: rgba(255,255,255,0.2); border-color: transparent; color: white;">
                        Request Leave
                    </a>
                    <?php if ($trainingId): ?>
                        <a href="<?= url('/calendar/' . $trainingId . '/ics') ?>" class="btn" style="background: transparent; border-color: rgba(255,255,255,0.5); color: white;">
                            Add to Calendar
                        </a>
                    <?php endif; ?>
                </div>
            </section>
        <?php else: ?>
            <section class="next-training-card" style="background: var(--color-text-secondary, #666);">
                <span class="next-training-label">Next Training</span>
                <p class="next-training-date">No upcoming trainings scheduled</p>
                <div class="next-training-actions">
                    <a href="<?= url('/calendar') ?>" class="btn" style="background: rgba(255,255,255,0.2); border-color: transparent; color: white;">
                        View Calendar
                    </a>
                </div>
            </section>
        <?php endif; ?>

        <!-- Upcoming Events -->
        <section class="upcoming-events mb-4">
            <div class="section-header">
                <h2>Upcoming Events</h2>
                <a href="<?= url('/calendar') ?>" class="section-link">View all</a>
            </div>
            <?php if (!empty($upcomingEvents)): ?>
                <div class="events-list">
                    <?php foreach ($upcomingEvents as $event): ?>
                        <?php $eventDate = new DateTime($event['start_time'], new DateTimeZone('Pacific/Auckland')); ?>
                        <a href="<?= url('/calendar/' . (int)$event['id']) ?>" class="event-item card">
                            <div class="event-date-badge">
                                <span class="event-day"><?= $eventDate->format('j') ?></span>
                                <span class="event-month"><?= $eventDate->format('M') ?></span>
                            </div>
                            <div class="event-info">
                                <span class="event-title"><?= e($event['title']) ?></span>
                                <span class="event-time"><?= $eventDate->format('g:i A') ?></span>
                            </div>
                            <?php if (!empty($event['is_training'])): ?>
                                <span class="event-badge training">Training</span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body">
                        <p class="text-secondary text-center p-3">No upcoming events</p>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <!-- Notices -->
        <section class="notices-section mb-4">
            <div class="section-header">
                <h2>Notices</h2>
                <a href="<?= url('/notices') ?>" class="section-link">View all</a>
            </div>
            <?php if (!empty($recentNotices)): ?>
                <div class="notices-list">
                    <?php foreach ($recentNotices as $notice): ?>
                        <a href="<?= url('/notices/' . (int)$notice['id']) ?>" class="notice-item card notice-type-<?= e($notice['type']) ?>">
                            <div class="notice-content">
                                <?php if ($notice['type'] === 'urgent'): ?>
                                    <span class="notice-badge urgent">Urgent</span>
                                <?php elseif ($notice['type'] === 'sticky'): ?>
                                    <span class="notice-badge sticky">Pinned</span>
                                <?php endif; ?>
                                <span class="notice-title"><?= e($notice['title']) ?></span>
                                <span class="notice-meta"><?= timeAgo($notice['created_at']) ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body">
                        <p class="text-secondary text-center p-3">No notices to display</p>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <!-- Leave Status -->
        <section class="leave-section">
            <div class="section-header">
                <h2>Your Leave Status</h2>
                <a href="<?= url('/leave') ?>" class="section-link">Manage</a>
            </div>
            <?php if (!empty($pendingLeave)): ?>
                <div class="leave-list">
                    <?php foreach ($pendingLeave as $leave): ?>
                        <div class="leave-item card">
                            <div class="leave-info">
                                <span class="leave-date"><?= date('l, j F', strtotime($leave['training_date'])) ?></span>
                                <span class="leave-status status-pending">Pending</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body">
                        <p class="text-secondary text-center p-3">No pending leave requests</p>
                    </div>
                </div>
            <?php endif; ?>
        </section>

    <?php else: ?>
        <!-- Guest View -->
        <section class="welcome-section text-center" style="padding: 3rem 1rem;">
            <h1><?= e($appName) ?></h1>
            <p class="text-secondary mb-4">Fire brigade member portal</p>
            <a href="<?= url('/auth/login') ?>" class="btn btn-primary btn-lg">Sign In</a>
        </section>
    <?php endif; ?>
</div>

<style>
.page-home {
    padding: var(--spacing-md, 1rem);
    max-width: 600px;
    margin: 0 auto;
}

.welcome-section {
    margin-bottom: var(--spacing-lg, 1.5rem);
}

.welcome-section h1 {
    margin: 0 0 0.25rem 0;
    font-size: 1.5rem;
}

.next-training-card {
    background: linear-gradient(135deg, var(--color-primary, #D32F2F), #B71C1C);
    color: white;
    border-radius: var(--radius-lg, 12px);
    padding: var(--spacing-lg, 1.5rem);
    margin-bottom: var(--spacing-lg, 1.5rem);
    text-align: center;
}

.next-training-label {
    display: block;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    opacity: 0.8;
    margin-bottom: 0.5rem;
}

.next-training-date {
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0 0 0.25rem 0;
}

.next-training-time {
    font-size: 1rem;
    opacity: 0.9;
    margin: 0 0 1rem 0;
}

.next-training-actions {
    display: flex;
    gap: 0.75rem;
    justify-content: center;
    flex-wrap: wrap;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}

.section-header h2 {
    margin: 0;
    font-size: 1.125rem;
}

.section-link {
    font-size: 0.875rem;
    color: var(--color-accent, #1976D2);
    text-decoration: none;
}

.section-link:hover {
    text-decoration: underline;
}

/* Events list */
.events-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.event-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem;
    text-decoration: none;
    color: inherit;
}

.event-item:hover {
    background: var(--color-background-hover, #f5f5f5);
}

.event-date-badge {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 45px;
    padding: 0.25rem;
    background: var(--color-primary, #D32F2F);
    color: white;
    border-radius: var(--radius-sm, 4px);
}

.event-day {
    font-size: 1.25rem;
    font-weight: 700;
    line-height: 1;
}

.event-month {
    font-size: 0.625rem;
    text-transform: uppercase;
}

.event-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
}

.event-title {
    font-weight: 500;
}

.event-time {
    font-size: 0.875rem;
    color: var(--color-text-secondary, #666);
}

.event-badge {
    font-size: 0.625rem;
    padding: 0.125rem 0.5rem;
    border-radius: 999px;
    text-transform: uppercase;
    font-weight: 500;
}

.event-badge.training {
    background: #e8f5e9;
    color: #2e7d32;
}

/* Notices list */
.notices-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.notice-item {
    display: block;
    padding: 0.75rem;
    text-decoration: none;
    color: inherit;
}

.notice-item:hover {
    background: var(--color-background-hover, #f5f5f5);
}

.notice-content {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}

.notice-title {
    flex: 1;
    font-weight: 500;
}

.notice-meta {
    font-size: 0.75rem;
    color: var(--color-text-secondary, #666);
}

.notice-badge {
    font-size: 0.625rem;
    padding: 0.125rem 0.5rem;
    border-radius: 999px;
    text-transform: uppercase;
    font-weight: 500;
}

.notice-badge.urgent {
    background: #ffebee;
    color: #c62828;
}

.notice-badge.sticky {
    background: #fff3e0;
    color: #ef6c00;
}

.notice-type-urgent {
    border-left: 3px solid #c62828;
}

.notice-type-sticky {
    border-left: 3px solid #ef6c00;
}

/* Leave list */
.leave-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.leave-item {
    padding: 0.75rem;
}

.leave-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.leave-date {
    font-weight: 500;
}

.leave-status {
    font-size: 0.75rem;
    padding: 0.125rem 0.5rem;
    border-radius: 999px;
    font-weight: 500;
}

.status-pending {
    background: #fff3e0;
    color: #ef6c00;
}

.status-approved {
    background: #e8f5e9;
    color: #2e7d32;
}
</style>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../layouts/main.php';
