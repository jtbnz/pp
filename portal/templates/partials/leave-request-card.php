<?php
declare(strict_types=1);

/**
 * Leave Request Card Partial
 *
 * Displays a single leave request with status and actions.
 * Variables available:
 * - $request: array with leave request data
 */

$isPast = strtotime($request['training_date']) < strtotime('today');
$isPending = $request['status'] === 'pending';
$isApproved = $request['status'] === 'approved';
$isDenied = $request['status'] === 'denied';
?>

<div class="leave-request-card card <?= $request['status'] ?> <?= $isPast ? 'past' : '' ?>" data-request-id="<?= $request['id'] ?>">
    <div class="card-body">
        <div class="request-header">
            <div class="request-date">
                <span class="day"><?= date('l', strtotime($request['training_date'])) ?></span>
                <span class="date"><?= date('j F Y', strtotime($request['training_date'])) ?></span>
            </div>
            <div class="request-status status-<?= $request['status'] ?>">
                <?php if ($isPending): ?>
                    <span class="status-icon">&#8987;</span>
                    <span class="status-text">Pending</span>
                <?php elseif ($isApproved): ?>
                    <span class="status-icon">&#10003;</span>
                    <span class="status-text">Approved</span>
                <?php elseif ($isDenied): ?>
                    <span class="status-icon">&#10007;</span>
                    <span class="status-text">Denied</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($request['reason'])): ?>
            <div class="request-reason">
                <span class="reason-label">Reason:</span>
                <p class="reason-text"><?= e($request['reason']) ?></p>
            </div>
        <?php endif; ?>

        <div class="request-footer">
            <span class="request-meta">
                Requested <?= timeAgo($request['requested_at']) ?>
            </span>

            <?php if (!$isPast && $isPending): ?>
                <button
                    type="button"
                    class="btn btn-sm cancel-request-btn"
                    onclick="Leave.showCancelModal(<?= $request['id'] ?>, '<?= date('j F', strtotime($request['training_date'])) ?>')"
                >
                    Cancel
                </button>
            <?php elseif (!empty($request['decided_by_name'])): ?>
                <span class="decided-by">
                    <?= $isApproved ? 'Approved' : 'Denied' ?> by <?= e($request['decided_by_name']) ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
</div>
