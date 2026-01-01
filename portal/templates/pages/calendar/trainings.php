<?php
declare(strict_types=1);

/**
 * Training Nights Template
 *
 * Displays and manages training night schedule.
 */

global $config;

$pageTitle = $pageTitle ?? 'Training Nights';
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';
$user = currentUser();
$isAdmin = $isAdmin ?? false;
$trainings = $trainings ?? [];
$from = $from ?? date('Y-m-d');
$months = $months ?? 12;

// Get flash message if any
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Start output buffering for content
ob_start();
?>

<div class="page-trainings">
    <div class="page-header">
        <a href="<?= url('/calendar') ?>" class="back-link">&larr; Back to Calendar</a>
        <h1>Training Nights</h1>
    </div>

    <?php if ($flash): ?>
        <div class="flash-message flash-<?= e($flash['type']) ?>">
            <?= e($flash['message']) ?>
            <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
        <!-- Generate Trainings Form -->
        <div class="card generate-card mb-4">
            <div class="card-header">
                <h2>Generate Training Events</h2>
            </div>
            <div class="card-body">
                <form action="<?= url('/calendar/trainings/generate') ?>" method="POST" class="generate-form">
                    <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="from" class="form-label">Start Date</label>
                            <input type="date" id="from" name="from" class="form-input"
                                   value="<?= e($from) ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="months" class="form-label">Months Ahead</label>
                            <select id="months" name="months" class="form-select">
                                <option value="3" <?= $months == 3 ? 'selected' : '' ?>>3 months</option>
                                <option value="6" <?= $months == 6 ? 'selected' : '' ?>>6 months</option>
                                <option value="12" <?= $months == 12 ? 'selected' : '' ?>>12 months</option>
                                <option value="24" <?= $months == 24 ? 'selected' : '' ?>>24 months</option>
                            </select>
                        </div>
                    </div>

                    <p class="form-hint">
                        Training nights are scheduled for Mondays at 7:00 PM. When a Monday falls on an
                        Auckland public holiday, training is automatically moved to Tuesday.
                    </p>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Generate Training Events</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Training Schedule -->
    <div class="card">
        <div class="card-header">
            <h2>Upcoming Training Schedule</h2>
        </div>
        <div class="card-body">
            <?php if (empty($trainings)): ?>
                <p class="no-trainings">No training nights scheduled.</p>
            <?php else: ?>
                <div class="trainings-list">
                    <?php
                    $currentMonth = '';
                    foreach ($trainings as $training):
                        $trainingDate = new DateTime($training['date'], new DateTimeZone('Pacific/Auckland'));
                        $monthYear = $trainingDate->format('F Y');

                        if ($monthYear !== $currentMonth):
                            if ($currentMonth !== '') echo '</div>'; // Close previous month group
                            $currentMonth = $monthYear;
                    ?>
                        <h3 class="month-header"><?= e($monthYear) ?></h3>
                        <div class="month-trainings">
                    <?php endif; ?>

                        <div class="training-item <?= $training['is_holiday'] ?? false ? 'holiday-shifted' : '' ?> <?= $training['exists'] ?? false ? 'exists' : 'not-created' ?>">
                            <div class="training-date">
                                <span class="day-name"><?= $trainingDate->format('l') ?></span>
                                <span class="day-number"><?= $trainingDate->format('j') ?></span>
                            </div>

                            <div class="training-info">
                                <span class="training-time">7:00 PM - 9:00 PM</span>
                                <?php if ($training['is_holiday'] ?? false): ?>
                                    <span class="holiday-note">
                                        Shifted from Monday (<?= e($training['holiday_name'] ?? 'Public Holiday') ?>)
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="training-status">
                                <?php if ($training['exists'] ?? false): ?>
                                    <span class="status-badge status-created">Created</span>
                                    <?php if ($training['id'] ?? false): ?>
                                        <a href="<?= url('/calendar/' . $training['id']) ?>" class="btn btn-sm">View</a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="status-badge status-pending">Pending</span>
                                <?php endif; ?>
                            </div>
                        </div>

                    <?php endforeach; ?>
                    </div> <!-- Close last month group -->
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.page-trainings {
    padding: var(--spacing-md);
    max-width: 800px;
    margin: 0 auto;
}

.page-header {
    margin-bottom: var(--spacing-lg);
}

.back-link {
    display: inline-block;
    margin-bottom: var(--spacing-sm);
    color: var(--color-text-secondary);
    text-decoration: none;
}

.page-header h1 {
    margin: 0;
    font-size: var(--font-size-xl);
}

.generate-card .card-header h2,
.card > .card-header h2 {
    margin: 0;
    font-size: var(--font-size-lg);
}

.generate-form {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-md);
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--spacing-md);
}

@media (max-width: 480px) {
    .form-row {
        grid-template-columns: 1fr;
    }
}

.form-hint {
    margin: 0;
    font-size: var(--font-size-sm);
    color: var(--color-text-secondary);
}

.form-actions {
    display: flex;
    justify-content: flex-end;
}

.no-trainings {
    text-align: center;
    color: var(--color-text-secondary);
    padding: var(--spacing-xl);
}

.trainings-list {
    display: flex;
    flex-direction: column;
}

.month-header {
    font-size: var(--font-size-md);
    font-weight: var(--font-weight-semibold);
    margin: var(--spacing-lg) 0 var(--spacing-sm);
    color: var(--color-text-secondary);
    border-bottom: 1px solid var(--color-divider);
    padding-bottom: var(--spacing-xs);
}

.month-header:first-child {
    margin-top: 0;
}

.month-trainings {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
}

.training-item {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    padding: var(--spacing-md);
    background: var(--color-background);
    border-radius: var(--radius-md);
    border-left: 4px solid var(--color-primary);
}

.training-item.holiday-shifted {
    border-left-color: var(--color-warning);
}

.training-item.not-created {
    opacity: 0.7;
    border-left-style: dashed;
}

.training-date {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 60px;
    text-align: center;
}

.training-date .day-name {
    font-size: var(--font-size-xs);
    color: var(--color-text-secondary);
    text-transform: uppercase;
}

.training-date .day-number {
    font-size: var(--font-size-xl);
    font-weight: var(--font-weight-bold);
}

.training-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: var(--spacing-xs);
}

.training-time {
    font-weight: var(--font-weight-medium);
}

.holiday-note {
    font-size: var(--font-size-sm);
    color: var(--color-warning);
}

.training-status {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.status-badge {
    display: inline-block;
    padding: var(--spacing-xs) var(--spacing-sm);
    border-radius: var(--radius-sm);
    font-size: var(--font-size-xs);
    font-weight: var(--font-weight-medium);
    text-transform: uppercase;
}

.status-created {
    background: rgba(76, 175, 80, 0.1);
    color: #2e7d32;
}

.status-pending {
    background: var(--color-background);
    color: var(--color-text-secondary);
}

@media (max-width: 480px) {
    .training-item {
        flex-wrap: wrap;
    }

    .training-status {
        width: 100%;
        justify-content: flex-end;
        margin-top: var(--spacing-sm);
    }
}
</style>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../layouts/main.php';
