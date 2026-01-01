<?php
declare(strict_types=1);

/**
 * Admin Create Event Template
 *
 * Form to create a new event (admin only).
 */

global $config;

$pageTitle = $pageTitle ?? 'Create Event';
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';
$user = currentUser();

// Get form errors and data from session
$errors = $_SESSION['form_errors'] ?? [];
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['form_data']);

// Start output buffering for content
ob_start();
?>

<div class="page-admin-event-form">
    <nav class="breadcrumb-nav mb-3">
        <a href="<?= url('/admin/events') ?>" class="breadcrumb-link">&larr; Back to Events</a>
    </nav>

    <header class="page-header mb-4">
        <h1><?= e($pageTitle) ?></h1>
    </header>

    <div class="card">
        <div class="card-body">
            <form action="<?= url('/admin/events') ?>" method="POST" class="event-form">
                <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">

                <div class="form-group">
                    <label for="title" class="form-label">Title <span class="required">*</span></label>
                    <input
                        type="text"
                        id="title"
                        name="title"
                        class="form-input <?= !empty($errors['title']) ? 'error' : '' ?>"
                        value="<?= e($formData['title'] ?? '') ?>"
                        required
                        maxlength="200"
                        placeholder="Enter event title"
                    >
                    <?php if (!empty($errors['title'])): ?>
                        <span class="form-error"><?= e($errors['title']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="description" class="form-label">Description</label>
                    <textarea
                        id="description"
                        name="description"
                        class="form-textarea"
                        rows="4"
                        placeholder="Enter event description (optional)"
                    ><?= e($formData['description'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label for="location" class="form-label">Location</label>
                    <input
                        type="text"
                        id="location"
                        name="location"
                        class="form-input"
                        value="<?= e($formData['location'] ?? '') ?>"
                        placeholder="e.g., Fire Station, Training Ground"
                    >
                </div>

                <fieldset class="form-fieldset">
                    <legend>Date & Time</legend>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="start_date" class="form-label">Start Date <span class="required">*</span></label>
                            <input
                                type="date"
                                id="start_date"
                                name="start_date"
                                class="form-input <?= !empty($errors['start_date']) ? 'error' : '' ?>"
                                value="<?= e($formData['start_date'] ?? '') ?>"
                                required
                            >
                            <?php if (!empty($errors['start_date'])): ?>
                                <span class="form-error"><?= e($errors['start_date']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group time-input" id="start-time-group">
                            <label for="start_time" class="form-label">Start Time</label>
                            <input
                                type="time"
                                id="start_time"
                                name="start_time"
                                class="form-input"
                                value="<?= e($formData['start_time'] ?? '19:00') ?>"
                            >
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="end_date" class="form-label">End Date</label>
                            <input
                                type="date"
                                id="end_date"
                                name="end_date"
                                class="form-input"
                                value="<?= e($formData['end_date'] ?? '') ?>"
                            >
                            <span class="form-hint">Leave empty for same-day events</span>
                        </div>

                        <div class="form-group time-input" id="end-time-group">
                            <label for="end_time" class="form-label">End Time</label>
                            <input
                                type="time"
                                id="end_time"
                                name="end_time"
                                class="form-input"
                                value="<?= e($formData['end_time'] ?? '21:00') ?>"
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-checkbox">
                            <input type="checkbox" name="all_day" value="1" id="all_day"
                                   <?= !empty($formData['all_day']) ? 'checked' : '' ?>>
                            <span class="checkbox-label">All day event</span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="form-fieldset">
                    <legend>Event Type</legend>

                    <div class="form-group">
                        <label class="form-checkbox">
                            <input type="checkbox" name="is_training" value="1" id="is_training"
                                   <?= !empty($formData['is_training']) ? 'checked' : '' ?>>
                            <span class="checkbox-label">This is a training night</span>
                        </label>
                        <span class="form-hint">Training nights can have leave requests and sync to DLB attendance</span>
                    </div>
                </fieldset>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Create Event</button>
                    <a href="<?= url('/admin/events') ?>" class="btn">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <div class="admin-nav-back mt-4">
        <a href="<?= url('/admin') ?>" class="btn btn-text">&larr; Back to Dashboard</a>
    </div>
</div>

<style>
.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.form-fieldset {
    border: 1px solid var(--border, #e0e0e0);
    border-radius: 8px;
    padding: 1rem;
    margin: 1rem 0;
}

.form-fieldset legend {
    padding: 0 0.5rem;
    font-weight: 600;
    font-size: 0.875rem;
}

.form-checkbox {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.form-checkbox input[type="checkbox"] {
    width: 1.25rem;
    height: 1.25rem;
    cursor: pointer;
}

.checkbox-label {
    font-weight: 500;
}

.form-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 1.5rem;
}

.required {
    color: var(--error, #D32F2F);
}

.time-input.hidden {
    display: none;
}
</style>

<script>
    // Toggle time inputs when all-day is checked
    const allDayCheckbox = document.getElementById('all_day');
    const startTimeGroup = document.getElementById('start-time-group');
    const endTimeGroup = document.getElementById('end-time-group');

    function toggleTimeInputs() {
        if (allDayCheckbox.checked) {
            startTimeGroup.classList.add('hidden');
            endTimeGroup.classList.add('hidden');
        } else {
            startTimeGroup.classList.remove('hidden');
            endTimeGroup.classList.remove('hidden');
        }
    }

    allDayCheckbox.addEventListener('change', toggleTimeInputs);
    toggleTimeInputs(); // Initial state

    // Copy start date to end date if end date is empty
    document.getElementById('start_date').addEventListener('change', function() {
        const endDate = document.getElementById('end_date');
        if (!endDate.value) {
            endDate.value = this.value;
        }
    });
</script>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../../layouts/main.php';
?>
