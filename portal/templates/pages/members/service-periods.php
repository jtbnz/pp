<?php
declare(strict_types=1);

/**
 * Member Service Periods Template
 *
 * Displays and manages a member's service periods.
 */

global $config;

$pageTitle = $pageTitle ?? 'Service Periods';
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';
$user = currentUser();
$member = $member ?? [];
$servicePeriods = $servicePeriods ?? [];
$serviceInfo = $serviceInfo ?? ['display' => '0 years', 'total_days' => 0];
$canEdit = $canEdit ?? false;

// Get flash message if any
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Start output buffering for content
ob_start();
?>

<div class="page-service-periods">
    <div class="page-header">
        <a href="<?= url('/members/' . ($member['id'] ?? '')) ?>" class="back-link">&larr; Back to Profile</a>
        <h1>Service Periods</h1>
        <p class="page-subtitle"><?= e($member['name'] ?? '') ?></p>
    </div>

    <?php if ($flash): ?>
        <div class="flash-message flash-<?= e($flash['type']) ?>">
            <?= e($flash['message']) ?>
            <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Service Summary -->
    <div class="card service-summary-card mb-4">
        <div class="service-summary">
            <div class="service-stat">
                <span class="stat-value"><?= e($serviceInfo['display']) ?></span>
                <span class="stat-label">Total Service</span>
            </div>
            <div class="service-stat">
                <span class="stat-value"><?= number_format($serviceInfo['total_days']) ?></span>
                <span class="stat-label">Days</span>
            </div>
            <div class="service-stat">
                <span class="stat-value"><?= count($servicePeriods) ?></span>
                <span class="stat-label">Periods</span>
            </div>
        </div>
    </div>

    <?php if ($canEdit): ?>
        <!-- Add Service Period Form -->
        <div class="card mb-4">
            <div class="card-header">
                <h2>Add Service Period</h2>
            </div>
            <div class="card-body">
                <form action="<?= url('/members/' . ($member['id'] ?? '') . '/service-periods') ?>" method="POST" class="service-period-form">
                    <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="start_date" class="form-label">Start Date *</label>
                            <input type="date" id="start_date" name="start_date" class="form-input" required>
                        </div>

                        <div class="form-group">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" id="end_date" name="end_date" class="form-input">
                            <span class="form-hint">Leave empty if currently serving</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="notes" class="form-label">Notes</label>
                        <input type="text" id="notes" name="notes" class="form-input"
                               placeholder="Reason for break, transfer details, etc.">
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Add Period</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Service Periods List -->
    <div class="card">
        <div class="card-header">
            <h2>Service History</h2>
        </div>
        <div class="card-body">
            <?php if (empty($servicePeriods)): ?>
                <p class="no-periods">No service periods recorded.</p>
            <?php else: ?>
                <div class="periods-list">
                    <?php foreach ($servicePeriods as $period): ?>
                        <?php
                        $startDate = new DateTime($period['start_date']);
                        $endDate = $period['end_date'] ? new DateTime($period['end_date']) : null;
                        $isCurrent = !$endDate;

                        // Calculate duration
                        $endForCalc = $endDate ?? new DateTime();
                        $interval = $startDate->diff($endForCalc);
                        $durationText = '';
                        if ($interval->y > 0) $durationText .= $interval->y . ' year' . ($interval->y > 1 ? 's' : '') . ' ';
                        if ($interval->m > 0) $durationText .= $interval->m . ' month' . ($interval->m > 1 ? 's' : '') . ' ';
                        if ($interval->y === 0 && $interval->m === 0) $durationText = $interval->d . ' days';
                        ?>
                        <div class="period-item <?= $isCurrent ? 'current' : '' ?>">
                            <div class="period-dates">
                                <span class="period-start"><?= $startDate->format('j M Y') ?></span>
                                <span class="period-arrow">&rarr;</span>
                                <span class="period-end">
                                    <?= $endDate ? $endDate->format('j M Y') : 'Present' ?>
                                </span>
                            </div>

                            <div class="period-duration">
                                <?= trim($durationText) ?>
                                <?php if ($isCurrent): ?>
                                    <span class="current-badge">Active</span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($period['notes'])): ?>
                                <div class="period-notes">
                                    <?= e($period['notes']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($canEdit): ?>
                                <div class="period-actions">
                                    <form action="<?= url('/members/' . ($member['id'] ?? '') . '/service-periods/' . $period['id']) ?>" method="POST"
                                          onsubmit="return confirm('Are you sure you want to delete this service period?');">
                                        <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button type="submit" class="btn btn-sm btn-text btn-danger">Delete</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.page-service-periods {
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

.page-subtitle {
    margin: var(--spacing-xs) 0 0;
    color: var(--color-text-secondary);
}

.service-summary-card {
    background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark, #b71c1c));
    color: white;
}

.service-summary {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--spacing-md);
    padding: var(--spacing-lg);
    text-align: center;
}

.service-stat {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-xs);
}

.stat-value {
    font-size: var(--font-size-xl);
    font-weight: var(--font-weight-bold);
}

.stat-label {
    font-size: var(--font-size-sm);
    opacity: 0.9;
    text-transform: uppercase;
}

.card-header h2 {
    margin: 0;
    font-size: var(--font-size-lg);
}

.service-period-form {
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

    .service-summary {
        grid-template-columns: 1fr;
        gap: var(--spacing-sm);
    }
}

.form-hint {
    display: block;
    font-size: var(--font-size-xs);
    color: var(--color-text-secondary);
    margin-top: var(--spacing-xs);
}

.form-actions {
    display: flex;
    justify-content: flex-end;
}

.no-periods {
    text-align: center;
    color: var(--color-text-secondary);
    padding: var(--spacing-xl);
}

.periods-list {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-md);
}

.period-item {
    padding: var(--spacing-md);
    background: var(--color-background);
    border-radius: var(--radius-md);
    border-left: 4px solid var(--color-text-secondary);
}

.period-item.current {
    border-left-color: var(--color-success);
}

.period-dates {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    font-weight: var(--font-weight-medium);
    margin-bottom: var(--spacing-xs);
}

.period-arrow {
    color: var(--color-text-secondary);
}

.period-duration {
    font-size: var(--font-size-sm);
    color: var(--color-text-secondary);
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.current-badge {
    display: inline-block;
    padding: var(--spacing-xs) var(--spacing-sm);
    background: rgba(76, 175, 80, 0.1);
    color: #2e7d32;
    border-radius: var(--radius-sm);
    font-size: var(--font-size-xs);
    font-weight: var(--font-weight-medium);
    text-transform: uppercase;
}

.period-notes {
    margin-top: var(--spacing-sm);
    font-size: var(--font-size-sm);
    color: var(--color-text-secondary);
    font-style: italic;
}

.period-actions {
    margin-top: var(--spacing-sm);
    display: flex;
    justify-content: flex-end;
}

.btn-danger {
    color: var(--color-error);
}
</style>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../layouts/main.php';
