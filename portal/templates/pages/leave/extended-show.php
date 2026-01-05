<?php
declare(strict_types=1);

/**
 * Extended Leave Request Detail View
 *
 * Shows details of an extended leave request.
 * Variables available:
 * - $request: array with extended leave request data
 * - $trainings: array of trainings affected
 * - $isOwner: bool - is user the request owner
 * - $isOfficer: bool - is user an officer
 * - $isCFO: bool - is user the CFO
 * - $canApprove: bool - can user approve this request
 */

global $config;

$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';

$isPending = $request['status'] === 'pending';
$isApproved = $request['status'] === 'approved';
$isDenied = $request['status'] === 'denied';

// Start output buffering for content
ob_start();
?>

<div class="page-leave-extended-show">
    <div class="container">
        <!-- Page Header -->
        <header class="page-header">
            <div class="page-header-left">
                <a href="<?= url('/leave') ?>" class="back-link">&larr; Back to Leave</a>
                <h1>Extended Leave Request</h1>
            </div>
            <div class="status-badge status-<?= $request['status'] ?>">
                <?php if ($isPending): ?>
                    <span class="status-icon">&#8987;</span> Pending
                <?php elseif ($isApproved): ?>
                    <span class="status-icon">&#10003;</span> Approved
                <?php elseif ($isDenied): ?>
                    <span class="status-icon">&#10007;</span> Denied
                <?php endif; ?>
            </div>
        </header>

        <!-- Request Details Card -->
        <div class="card mb-4">
            <div class="card-body">
                <!-- Member Info (for officers viewing) -->
                <?php if ($isOfficer && !$isOwner): ?>
                <div class="member-info-section mb-4">
                    <div class="member-info">
                        <span class="member-avatar">
                            <?= strtoupper(substr($request['member_name'], 0, 1)) ?>
                        </span>
                        <div class="member-details">
                            <span class="member-name"><?= e($request['member_name']) ?></span>
                            <?php if (!empty($request['member_rank'])): ?>
                                <span class="member-rank"><?= e($request['member_rank']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Date Range -->
                <div class="detail-row">
                    <div class="detail-item">
                        <span class="detail-label">From</span>
                        <span class="detail-value"><?= date('l, j F Y', strtotime($request['start_date'])) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">To</span>
                        <span class="detail-value"><?= date('l, j F Y', strtotime($request['end_date'])) ?></span>
                    </div>
                </div>

                <!-- Duration -->
                <?php
                $startDate = new DateTime($request['start_date']);
                $endDate = new DateTime($request['end_date']);
                $duration = $startDate->diff($endDate)->days + 1;
                ?>
                <div class="detail-row">
                    <div class="detail-item">
                        <span class="detail-label">Duration</span>
                        <span class="detail-value"><?= $duration ?> day<?= $duration !== 1 ? 's' : '' ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Trainings Affected</span>
                        <span class="detail-value"><?= (int)$request['trainings_affected'] ?></span>
                    </div>
                </div>

                <!-- Reason -->
                <?php if (!empty($request['reason'])): ?>
                <div class="detail-section">
                    <span class="detail-label">Reason</span>
                    <p class="detail-text"><?= e($request['reason']) ?></p>
                </div>
                <?php endif; ?>

                <!-- Meta Info -->
                <div class="meta-info">
                    <span>Requested <?= timeAgo($request['requested_at']) ?></span>
                    <?php if (!empty($request['decided_by_name'])): ?>
                        <span class="meta-divider">&bull;</span>
                        <span><?= $isApproved ? 'Approved' : 'Denied' ?> by <?= e($request['decided_by_name']) ?><?= !empty($request['decided_by_rank']) ? ' (' . e($request['decided_by_rank']) . ')' : '' ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Trainings List -->
        <?php if (!empty($trainings)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h2>Trainings During This Period</h2>
            </div>
            <div class="card-body">
                <div class="trainings-list">
                    <?php foreach ($trainings as $training): ?>
                    <div class="training-item <?= !empty($training['is_rescheduled']) ? 'rescheduled' : '' ?>">
                        <span class="training-date"><?= date('j M Y', strtotime($training['date'])) ?></span>
                        <span class="training-day"><?= $training['day_name'] ?></span>
                        <?php if (!empty($training['is_rescheduled'])): ?>
                            <span class="training-badge">Rescheduled from <?= date('j M', strtotime($training['original_date'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="card">
            <div class="card-body">
                <div class="action-buttons">
                    <?php if ($canApprove): ?>
                        <!-- CFO Approval Actions -->
                        <form method="POST" action="<?= url("/leave/extended/{$request['id']}/deny") ?>" class="inline-form" onsubmit="return confirm('Are you sure you want to deny this extended leave request?');">
                            <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                            <button type="submit" class="btn btn-danger">Deny</button>
                        </form>
                        <form method="POST" action="<?= url("/leave/extended/{$request['id']}/approve") ?>" class="inline-form" onsubmit="return confirm('Are you sure you want to approve this extended leave request?');">
                            <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                            <button type="submit" class="btn btn-primary">Approve</button>
                        </form>
                    <?php elseif ($isOwner && $isPending): ?>
                        <!-- Owner Cancel Action -->
                        <form method="POST" action="<?= url("/leave/extended/{$request['id']}/cancel") ?>" class="inline-form" onsubmit="return confirm('Are you sure you want to cancel this extended leave request?');">
                            <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                            <button type="submit" class="btn btn-danger">Cancel Request</button>
                        </form>
                    <?php elseif ($isPending && !$isCFO): ?>
                        <p class="text-secondary">This request is awaiting CFO approval.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.page-leave-extended-show .page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 1rem;
}

.page-leave-extended-show .status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border-radius: var(--border-radius);
    font-weight: 500;
    font-size: 0.9rem;
}

.page-leave-extended-show .status-badge.status-pending {
    background: var(--color-warning-bg, #fff3cd);
    color: var(--color-warning-text, #856404);
}

.page-leave-extended-show .status-badge.status-approved {
    background: var(--color-success-bg, #d4edda);
    color: var(--color-success-text, #155724);
}

.page-leave-extended-show .status-badge.status-denied {
    background: var(--color-error-bg, #f8d7da);
    color: var(--color-error-text, #721c24);
}

.page-leave-extended-show .member-info-section {
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--color-border);
}

.page-leave-extended-show .member-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.page-leave-extended-show .member-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--color-primary);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 1.25rem;
}

.page-leave-extended-show .member-details {
    display: flex;
    flex-direction: column;
}

.page-leave-extended-show .member-name {
    font-weight: 600;
    font-size: 1.1rem;
}

.page-leave-extended-show .member-rank {
    color: var(--color-text-secondary);
    font-size: 0.9rem;
}

.page-leave-extended-show .detail-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

@media (max-width: 500px) {
    .page-leave-extended-show .detail-row {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
}

.page-leave-extended-show .detail-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.page-leave-extended-show .detail-label {
    font-size: 0.8rem;
    text-transform: uppercase;
    color: var(--color-text-secondary);
    letter-spacing: 0.05em;
}

.page-leave-extended-show .detail-value {
    font-size: 1rem;
    font-weight: 500;
}

.page-leave-extended-show .detail-section {
    margin-bottom: 1.5rem;
}

.page-leave-extended-show .detail-text {
    margin: 0.5rem 0 0;
    line-height: 1.6;
}

.page-leave-extended-show .meta-info {
    padding-top: 1rem;
    border-top: 1px solid var(--color-border);
    color: var(--color-text-secondary);
    font-size: 0.85rem;
}

.page-leave-extended-show .meta-divider {
    margin: 0 0.5rem;
}

.page-leave-extended-show .card-header {
    padding: 1rem;
    border-bottom: 1px solid var(--color-border);
}

.page-leave-extended-show .card-header h2 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
}

.page-leave-extended-show .trainings-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.page-leave-extended-show .training-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--color-border);
}

.page-leave-extended-show .training-item:last-child {
    border-bottom: none;
}

.page-leave-extended-show .training-date {
    font-weight: 500;
    min-width: 100px;
}

.page-leave-extended-show .training-day {
    color: var(--color-text-secondary);
    flex-grow: 1;
}

.page-leave-extended-show .training-badge {
    font-size: 0.75rem;
    background: var(--color-warning-bg, #fff3cd);
    color: var(--color-warning-text, #856404);
    padding: 0.25rem 0.5rem;
    border-radius: calc(var(--border-radius) / 2);
}

.page-leave-extended-show .training-item.rescheduled {
    border-left: 3px solid var(--color-warning, #f59e0b);
    padding-left: 0.75rem;
    margin-left: -0.75rem;
}

.page-leave-extended-show .action-buttons {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    flex-wrap: wrap;
}

.page-leave-extended-show .inline-form {
    display: inline;
}

.page-leave-extended-show .btn-danger {
    background: var(--color-error);
    border-color: var(--color-error);
    color: white;
}

.page-leave-extended-show .btn-danger:hover {
    background: var(--color-error-dark, #c62828);
}
</style>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../layouts/main.php';
