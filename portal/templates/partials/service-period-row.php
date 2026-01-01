<?php
declare(strict_types=1);

/**
 * Service Period Row Partial
 *
 * Table row component for displaying a service period.
 *
 * Variables:
 * - $period: Service period data array
 * - $member: Parent member data (for URLs)
 * - $canEdit: Whether editing is allowed
 */

$startDate = new DateTimeImmutable($period['start_date']);
$endDate = $period['end_date']
    ? new DateTimeImmutable($period['end_date'])
    : new DateTimeImmutable('today');

$diff = $startDate->diff($endDate);
$years = $diff->y;
$months = $diff->m;
$days = $diff->d;

if ($years > 0) {
    $duration = "{$years}y {$months}m";
} elseif ($months > 0) {
    $duration = "{$months}m {$days}d";
} else {
    $duration = "{$days}d";
}
?>

<tr class="service-period-row" data-period-id="<?= $period['id'] ?>">
    <td class="period-start"><?= date('j M Y', strtotime($period['start_date'])) ?></td>
    <td class="period-end">
        <?php if ($period['end_date']): ?>
            <?= date('j M Y', strtotime($period['end_date'])) ?>
        <?php else: ?>
            <span class="current-badge">Current</span>
        <?php endif; ?>
    </td>
    <td class="period-duration"><?= e($duration) ?></td>
    <td class="period-notes">
        <?php if ($period['notes']): ?>
            <span class="notes-text" title="<?= e($period['notes']) ?>">
                <?= e(strlen($period['notes']) > 30 ? substr($period['notes'], 0, 30) . '...' : $period['notes']) ?>
            </span>
        <?php else: ?>
            <span class="text-secondary">-</span>
        <?php endif; ?>
    </td>
    <?php if ($canEdit): ?>
        <td class="period-actions">
            <button type="button" class="btn btn-sm btn-text" onclick="editPeriod(<?= $period['id'] ?>)" aria-label="Edit">
                Edit
            </button>
            <form
                action="/members/<?= $member['id'] ?>/service-periods/<?= $period['id'] ?>"
                method="POST"
                class="inline-form"
                onsubmit="return confirm('Delete this service period?');"
            >
                <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="_method" value="DELETE">
                <button type="submit" class="btn btn-sm btn-text btn-danger-text" aria-label="Delete">
                    Delete
                </button>
            </form>
        </td>
    <?php endif; ?>
</tr>

<?php if ($canEdit): ?>
<!-- Edit modal for this period (hidden by default) -->
<tr class="edit-period-row" id="edit-period-<?= $period['id'] ?>" hidden>
    <td colspan="<?= $canEdit ? '5' : '4' ?>">
        <form
            action="/members/<?= $member['id'] ?>/service-periods/<?= $period['id'] ?>"
            method="POST"
            class="edit-period-form"
        >
            <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="_method" value="PUT">

            <div class="edit-form-row">
                <div class="edit-form-group">
                    <label>Start Date</label>
                    <input
                        type="date"
                        name="start_date"
                        class="form-input form-input-sm"
                        value="<?= e($period['start_date']) ?>"
                        required
                    >
                </div>

                <div class="edit-form-group">
                    <label>End Date</label>
                    <input
                        type="date"
                        name="end_date"
                        class="form-input form-input-sm"
                        value="<?= e($period['end_date'] ?? '') ?>"
                    >
                </div>

                <div class="edit-form-group edit-form-notes">
                    <label>Notes</label>
                    <input
                        type="text"
                        name="notes"
                        class="form-input form-input-sm"
                        value="<?= e($period['notes'] ?? '') ?>"
                        placeholder="Optional notes"
                    >
                </div>

                <div class="edit-form-actions">
                    <button type="submit" class="btn btn-sm btn-primary">Save</button>
                    <button type="button" class="btn btn-sm btn-text" onclick="cancelEditPeriod(<?= $period['id'] ?>)">Cancel</button>
                </div>
            </div>
        </form>
    </td>
</tr>
<?php endif; ?>

<style>
.service-period-row td {
    vertical-align: middle;
}

.current-badge {
    display: inline-block;
    background: #e8f5e9;
    color: #2e7d32;
    font-size: 0.75rem;
    padding: 0.125rem 0.5rem;
    border-radius: 999px;
    font-weight: 500;
}

.period-duration {
    font-weight: 500;
    color: var(--primary-color, #D32F2F);
}

.notes-text {
    font-size: 0.875rem;
    color: var(--text-secondary, #666);
}

.period-actions {
    text-align: right;
    white-space: nowrap;
}

.inline-form {
    display: inline;
}

.btn-danger-text {
    color: #c62828;
}

.btn-danger-text:hover {
    background: #ffebee;
}

.edit-period-row td {
    background: var(--bg-secondary, #f9f9f9);
    padding: 1rem;
}

.edit-period-form {
    margin: 0;
}

.edit-form-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: flex-end;
}

.edit-form-group {
    flex: 0 0 auto;
}

.edit-form-group label {
    display: block;
    font-size: 0.75rem;
    font-weight: 500;
    margin-bottom: 0.25rem;
    color: var(--text-secondary, #666);
}

.edit-form-notes {
    flex: 1;
    min-width: 150px;
}

.form-input-sm {
    padding: 0.375rem 0.5rem;
    font-size: 0.875rem;
}

.edit-form-actions {
    display: flex;
    gap: 0.5rem;
}

@media (max-width: 600px) {
    .edit-form-row {
        flex-direction: column;
        align-items: stretch;
    }

    .edit-form-group {
        width: 100%;
    }

    .edit-form-actions {
        justify-content: flex-end;
    }
}
</style>

<script>
function editPeriod(periodId) {
    // Hide the data row
    const dataRow = document.querySelector(`tr[data-period-id="${periodId}"]`);
    if (dataRow) {
        dataRow.hidden = true;
    }

    // Show the edit row
    const editRow = document.getElementById(`edit-period-${periodId}`);
    if (editRow) {
        editRow.hidden = false;
        editRow.querySelector('input[name="start_date"]').focus();
    }
}

function cancelEditPeriod(periodId) {
    // Show the data row
    const dataRow = document.querySelector(`tr[data-period-id="${periodId}"]`);
    if (dataRow) {
        dataRow.hidden = false;
    }

    // Hide the edit row
    const editRow = document.getElementById(`edit-period-${periodId}`);
    if (editRow) {
        editRow.hidden = true;
    }
}
</script>
