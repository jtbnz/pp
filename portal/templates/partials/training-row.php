<?php
declare(strict_types=1);

/**
 * Training Row Partial
 *
 * Displays a single training night with request leave button.
 * Variables available:
 * - $training: array with training data (date, time, day_name, is_rescheduled, original_date)
 */

$dateFormatted = date('j F Y', strtotime($training['date']));
$dayName = $training['day_name'];
$isRescheduled = $training['is_rescheduled'] ?? false;
$moveReason = $training['move_reason'] ?? null;
?>

<div class="training-row card" data-date="<?= $training['date'] ?>">
    <div class="card-body">
        <div class="training-info">
            <div class="training-date-info">
                <span class="training-day"><?= e($dayName) ?></span>
                <span class="training-date"><?= e($dateFormatted) ?></span>
                <?php if ($isRescheduled): ?>
                    <span class="training-rescheduled">
                        (Moved from <?= date('j M', strtotime($training['original_date'])) ?><?= $moveReason ? ' - ' . e($moveReason) : '' ?>)
                    </span>
                <?php endif; ?>
            </div>
            <div class="training-time">
                <?= e($training['time']) ?>
            </div>
        </div>

        <button
            type="button"
            class="btn btn-sm btn-outline request-leave-btn"
            onclick="Leave.showRequestModal('<?= $training['date'] ?>', '<?= e($dayName) ?>, <?= e($dateFormatted) ?>')"
        >
            Request Leave
        </button>
    </div>
</div>
