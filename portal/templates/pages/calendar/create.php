<?php
declare(strict_types=1);

/**
 * Create Event Template
 */

global $config;

$pageTitle = $pageTitle ?? 'Create Event';
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';
$user = currentUser();
$event = $event ?? [];
$errors = $errors ?? [];

// Start output buffering for content
ob_start();
?>

<div class="page-event-form">
    <a href="/calendar" class="back-link">&larr; Back to Calendar</a>

    <div class="card">
        <div class="card-header">
            <h1 class="card-title">Create Event</h1>
        </div>
        <div class="card-body">
            <form action="/calendar/events" method="POST" class="event-form">
                <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">

                <div class="form-group">
                    <label for="title" class="form-label">Title *</label>
                    <input type="text" id="title" name="title" class="form-input <?= isset($errors['title']) ? 'error' : '' ?>"
                           value="<?= e($event['title'] ?? '') ?>" required maxlength="200">
                    <?php if (isset($errors['title'])): ?>
                        <span class="form-error"><?= e($errors['title']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="description" class="form-label">Description</label>
                    <textarea id="description" name="description" class="form-textarea" rows="4"><?= e($event['description'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label for="location" class="form-label">Location</label>
                    <input type="text" id="location" name="location" class="form-input"
                           value="<?= e($event['location'] ?? 'Puke Fire Station') ?>" maxlength="200">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="start_time" class="form-label">Start Date/Time *</label>
                        <input type="datetime-local" id="start_time" name="start_time"
                               class="form-input <?= isset($errors['start_time']) ? 'error' : '' ?>"
                               value="<?= e($event['start_time'] ? date('Y-m-d\TH:i', strtotime($event['start_time'])) : '') ?>" required>
                        <?php if (isset($errors['start_time'])): ?>
                            <span class="form-error"><?= e($errors['start_time']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="end_time" class="form-label">End Date/Time</label>
                        <input type="datetime-local" id="end_time" name="end_time"
                               class="form-input <?= isset($errors['end_time']) ? 'error' : '' ?>"
                               value="<?= e($event['end_time'] ? date('Y-m-d\TH:i', strtotime($event['end_time'])) : '') ?>">
                        <?php if (isset($errors['end_time'])): ?>
                            <span class="form-error"><?= e($errors['end_time']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="all_day" value="1" <?= !empty($event['all_day']) ? 'checked' : '' ?>>
                        <span>All day event</span>
                    </label>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_training" value="1" <?= !empty($event['is_training']) ? 'checked' : '' ?>>
                        <span>Training night</span>
                    </label>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_visible" value="1" <?= ($event['is_visible'] ?? 1) ? 'checked' : '' ?>>
                        <span>Visible to members</span>
                    </label>
                </div>

                <div class="form-group">
                    <label for="recurrence_rule" class="form-label">Recurrence (optional)</label>
                    <select id="recurrence_preset" name="recurrence_preset" class="form-select">
                        <option value="">No recurrence</option>
                        <option value="FREQ=WEEKLY">Weekly</option>
                        <option value="FREQ=WEEKLY;INTERVAL=2">Every 2 weeks</option>
                        <option value="FREQ=MONTHLY">Monthly</option>
                        <option value="custom">Custom...</option>
                    </select>
                    <input type="hidden" id="recurrence_rule" name="recurrence_rule" value="<?= e($event['recurrence_rule'] ?? '') ?>">
                    <span class="form-hint">For training nights, use the training generator instead.</span>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Create Event</button>
                    <a href="/calendar" class="btn">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.page-event-form {
    padding: var(--spacing-md);
    max-width: 600px;
    margin: 0 auto;
}

.back-link {
    display: inline-block;
    margin-bottom: var(--spacing-md);
    color: var(--color-text-secondary);
    text-decoration: none;
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

.checkbox-label {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    cursor: pointer;
}

.checkbox-label input[type="checkbox"] {
    width: 20px;
    height: 20px;
}

.form-actions {
    display: flex;
    gap: var(--spacing-md);
    margin-top: var(--spacing-lg);
}
</style>

<script>
document.getElementById('recurrence_preset').addEventListener('change', function() {
    const ruleInput = document.getElementById('recurrence_rule');
    if (this.value === 'custom') {
        const custom = prompt('Enter RRULE (e.g., FREQ=WEEKLY;BYDAY=MO,WE,FR)');
        if (custom) {
            ruleInput.value = custom;
        } else {
            this.value = '';
            ruleInput.value = '';
        }
    } else {
        ruleInput.value = this.value;
    }
});
</script>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../layouts/main.php';
