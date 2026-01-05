<?php
declare(strict_types=1);

/**
 * Extended Leave Request Form
 *
 * Allows members to request extended/long-term leave with a date range.
 * Extended leave requires CFO approval only.
 */

global $config;

$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';

// Start output buffering for content
ob_start();
?>

<div class="page-leave-extended">
    <div class="container">
        <!-- Page Header -->
        <header class="page-header">
            <div class="page-header-left">
                <a href="<?= url('/leave') ?>" class="back-link">&larr; Back to Leave</a>
                <h1>Request Extended Leave</h1>
            </div>
        </header>

        <!-- Info Card -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="info-box">
                    <span class="info-icon">&#9432;</span>
                    <p>Extended leave is for long-term absences spanning multiple trainings. These requests require approval from the CFO.</p>
                </div>
            </div>
        </div>

        <!-- Extended Leave Form -->
        <form id="extended-leave-form" method="POST" action="<?= url('/leave/extended') ?>" class="card">
            <div class="card-body">
                <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date" class="form-label required">Start Date</label>
                        <input
                            type="date"
                            id="start_date"
                            name="start_date"
                            class="form-input"
                            required
                            min="<?= date('Y-m-d') ?>"
                        >
                        <span class="form-error" id="start_date_error"></span>
                    </div>

                    <div class="form-group">
                        <label for="end_date" class="form-label required">End Date</label>
                        <input
                            type="date"
                            id="end_date"
                            name="end_date"
                            class="form-input"
                            required
                            min="<?= date('Y-m-d') ?>"
                        >
                        <span class="form-error" id="end_date_error"></span>
                    </div>
                </div>

                <!-- Trainings Affected Preview -->
                <div id="trainings-preview" class="trainings-preview" hidden>
                    <div class="trainings-preview-header">
                        <span class="trainings-count">
                            <strong id="trainings-count-value">0</strong> training(s) affected
                        </span>
                    </div>
                    <div id="trainings-list" class="trainings-list"></div>
                </div>

                <div class="form-group">
                    <label for="reason" class="form-label">Reason (optional)</label>
                    <textarea
                        id="reason"
                        name="reason"
                        class="form-textarea"
                        rows="3"
                        placeholder="Enter reason for extended leave..."
                    ></textarea>
                    <span class="form-hint">This will be visible to the CFO when reviewing your request.</span>
                </div>

                <div class="form-actions">
                    <a href="<?= url('/leave') ?>" class="btn">Cancel</a>
                    <button type="submit" class="btn btn-primary" id="submit-btn" disabled>
                        Submit Request
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
.page-leave-extended .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

@media (max-width: 600px) {
    .page-leave-extended .form-row {
        grid-template-columns: 1fr;
    }
}

.page-leave-extended .info-box {
    display: flex;
    gap: 0.75rem;
    align-items: flex-start;
    background: var(--color-info-bg, #e3f2fd);
    padding: 1rem;
    border-radius: var(--border-radius);
    color: var(--color-info-text, #1565c0);
}

.page-leave-extended .info-box .info-icon {
    font-size: 1.25rem;
    flex-shrink: 0;
}

.page-leave-extended .info-box p {
    margin: 0;
    font-size: 0.9rem;
    line-height: 1.5;
}

.page-leave-extended .trainings-preview {
    background: var(--color-surface-alt, #f5f5f5);
    border-radius: var(--border-radius);
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.page-leave-extended .trainings-preview-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}

.page-leave-extended .trainings-count {
    font-size: 0.9rem;
    color: var(--color-text-secondary);
}

.page-leave-extended .trainings-count strong {
    color: var(--color-primary);
    font-size: 1.1rem;
}

.page-leave-extended .trainings-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    max-height: 200px;
    overflow-y: auto;
}

.page-leave-extended .training-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--color-surface, #fff);
    padding: 0.5rem 0.75rem;
    border-radius: calc(var(--border-radius) / 2);
    font-size: 0.85rem;
}

.page-leave-extended .training-item .training-date {
    font-weight: 500;
}

.page-leave-extended .training-item .training-day {
    color: var(--color-text-secondary);
}

.page-leave-extended .training-item.rescheduled {
    border-left: 3px solid var(--color-warning, #f59e0b);
}

.page-leave-extended .form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--color-border);
}

.page-leave-extended .required::after {
    content: ' *';
    color: var(--color-error);
}

.page-leave-extended .form-error {
    color: var(--color-error);
    font-size: 0.8rem;
    margin-top: 0.25rem;
    display: block;
}

/* Loading state */
.page-leave-extended #trainings-preview.loading .trainings-list::after {
    content: 'Calculating...';
    display: block;
    text-align: center;
    color: var(--color-text-secondary);
    padding: 1rem;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('extended-leave-form');
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    const trainingsPreview = document.getElementById('trainings-preview');
    const trainingsCountValue = document.getElementById('trainings-count-value');
    const trainingsList = document.getElementById('trainings-list');
    const submitBtn = document.getElementById('submit-btn');

    let debounceTimer;
    const basePath = window.BASE_PATH || '';

    // Update end date min when start date changes
    startDateInput.addEventListener('change', function() {
        if (this.value) {
            endDateInput.min = this.value;
            if (endDateInput.value && endDateInput.value < this.value) {
                endDateInput.value = this.value;
            }
        }
        debouncedCalculate();
    });

    endDateInput.addEventListener('change', debouncedCalculate);

    function debouncedCalculate() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(calculateTrainings, 300);
    }

    async function calculateTrainings() {
        const startDate = startDateInput.value;
        const endDate = endDateInput.value;

        // Clear errors
        document.getElementById('start_date_error').textContent = '';
        document.getElementById('end_date_error').textContent = '';

        // Validate
        if (!startDate || !endDate) {
            trainingsPreview.hidden = true;
            submitBtn.disabled = true;
            return;
        }

        if (endDate < startDate) {
            document.getElementById('end_date_error').textContent = 'End date must be after start date';
            trainingsPreview.hidden = true;
            submitBtn.disabled = true;
            return;
        }

        // Show loading state
        trainingsPreview.hidden = false;
        trainingsPreview.classList.add('loading');
        trainingsList.innerHTML = '';

        try {
            const response = await fetch(`${basePath}/leave/extended/calculate?start_date=${startDate}&end_date=${endDate}`, {
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();

            if (data.success) {
                trainingsCountValue.textContent = data.trainings_count;

                if (data.trainings && data.trainings.length > 0) {
                    trainingsList.innerHTML = data.trainings.map(t => `
                        <div class="training-item ${t.is_rescheduled ? 'rescheduled' : ''}">
                            <span class="training-date">${formatDate(t.date)}</span>
                            <span class="training-day">${t.day_name}</span>
                        </div>
                    `).join('');
                } else {
                    trainingsList.innerHTML = '<div class="training-item"><span>No trainings in this period</span></div>';
                }

                submitBtn.disabled = false;
            } else {
                throw new Error(data.error || 'Failed to calculate trainings');
            }
        } catch (error) {
            console.error('Error calculating trainings:', error);
            trainingsList.innerHTML = '<div class="training-item"><span>Error calculating trainings</span></div>';
            submitBtn.disabled = true;
        } finally {
            trainingsPreview.classList.remove('loading');
        }
    }

    function formatDate(dateStr) {
        const date = new Date(dateStr + 'T00:00:00');
        return date.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    // Form submission
    form.addEventListener('submit', function(e) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';
    });
});
</script>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../layouts/main.php';
