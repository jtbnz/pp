<?php
declare(strict_types=1);

/**
 * Pending Leave Requests Page (Officers View)
 *
 * Shows all pending leave requests for the brigade.
 * Variables available:
 * - $pendingRequests: array of pending leave requests
 * - $pendingExtendedRequests: array of pending extended leave requests
 * - $groupedRequests: requests grouped by training date
 * - $pendingCount: total pending single-training requests
 * - $pendingExtendedCount: total pending extended leave requests
 * - $isCFO: bool - is user the CFO
 */

global $config;

$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';

// Start output buffering for content
ob_start();
?>

<div class="page-leave-pending">
    <div class="container">
        <!-- Page Header -->
        <header class="page-header">
            <div class="page-header-left">
                <a href="<?= url('/leave') ?>" class="back-link">&larr; Back to Leave</a>
                <h1>Pending Approvals</h1>
            </div>
            <div class="pending-badges">
                <?php if ($pendingCount > 0): ?>
                    <span class="pending-badge"><?= $pendingCount ?> training</span>
                <?php endif; ?>
                <?php if (!empty($pendingExtendedCount) && $pendingExtendedCount > 0): ?>
                    <span class="pending-badge extended"><?= $pendingExtendedCount ?> extended</span>
                <?php endif; ?>
            </div>
        </header>

        <?php if (empty($pendingRequests) && empty($pendingExtendedRequests)): ?>
            <div class="card">
                <div class="card-body text-center p-5">
                    <p class="empty-icon">&#9989;</p>
                    <p class="text-secondary mb-0">No pending leave requests to review.</p>
                </div>
            </div>
        <?php else: ?>

        <?php if (!empty($pendingRequests)): ?>
            <!-- Group requests by training date -->
            <?php foreach ($groupedRequests as $date => $requests): ?>
                <section class="training-date-group mb-4">
                    <h2 class="date-group-header">
                        <span class="date-text">
                            <?= date('l, j F Y', strtotime($date)) ?>
                        </span>
                        <span class="request-count"><?= count($requests) ?> request<?= count($requests) !== 1 ? 's' : '' ?></span>
                    </h2>

                    <div class="pending-requests-list">
                        <?php foreach ($requests as $request): ?>
                            <div class="pending-request-card card" data-request-id="<?= $request['id'] ?>">
                                <div class="card-body">
                                    <div class="request-info">
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
                                        <div class="request-meta">
                                            <span class="request-time">
                                                Requested <?= timeAgo($request['requested_at']) ?>
                                            </span>
                                        </div>
                                    </div>

                                    <?php if (!empty($request['reason'])): ?>
                                        <div class="request-reason">
                                            <span class="reason-label">Reason:</span>
                                            <p class="reason-text"><?= e($request['reason']) ?></p>
                                        </div>
                                    <?php endif; ?>

                                    <div class="request-actions">
                                        <button
                                            type="button"
                                            class="btn btn-sm deny-btn"
                                            onclick="Leave.deny(<?= $request['id'] ?>, '<?= e($request['member_name']) ?>', '<?= date('j F', strtotime($date)) ?>')"
                                        >
                                            Deny
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-primary approve-btn"
                                            onclick="Leave.approve(<?= $request['id'] ?>, '<?= e($request['member_name']) ?>')"
                                        >
                                            Approve
                                        </button>
                                    </div>
                                </div>

                                <!-- Swipe actions (for touch) -->
                                <div class="swipe-actions">
                                    <div class="swipe-action deny" data-action="deny">
                                        <span>Deny</span>
                                    </div>
                                    <div class="swipe-action approve" data-action="approve">
                                        <span>Approve</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Extended Leave Requests Section -->
        <?php if (!empty($pendingExtendedRequests)): ?>
        <section class="extended-pending-section">
            <div class="section-header">
                <h2 class="section-title">
                    Extended Leave Requests
                    <span class="cfo-badge">CFO Only</span>
                </h2>
                <span class="request-count"><?= count($pendingExtendedRequests) ?> request<?= count($pendingExtendedRequests) !== 1 ? 's' : '' ?></span>
            </div>

            <?php if (!$isCFO): ?>
            <div class="card mb-4">
                <div class="card-body">
                    <p class="text-secondary mb-0">Extended leave requests require CFO approval. These are shown for visibility only.</p>
                </div>
            </div>
            <?php endif; ?>

            <div class="extended-pending-list">
                <?php foreach ($pendingExtendedRequests as $extRequest): ?>
                    <div class="extended-pending-card card" data-request-id="<?= $extRequest['id'] ?>">
                        <div class="card-body">
                            <div class="request-info">
                                <div class="member-info">
                                    <span class="member-avatar">
                                        <?= strtoupper(substr($extRequest['member_name'], 0, 1)) ?>
                                    </span>
                                    <div class="member-details">
                                        <span class="member-name"><?= e($extRequest['member_name']) ?></span>
                                        <?php if (!empty($extRequest['member_rank'])): ?>
                                            <span class="member-rank"><?= e($extRequest['member_rank']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="request-meta">
                                    <span class="request-time">
                                        Requested <?= timeAgo($extRequest['requested_at']) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="request-dates-info">
                                <div class="dates-row">
                                    <span class="dates-label">Period:</span>
                                    <span class="dates-value">
                                        <?= date('j M Y', strtotime($extRequest['start_date'])) ?>
                                        &ndash;
                                        <?= date('j M Y', strtotime($extRequest['end_date'])) ?>
                                    </span>
                                </div>
                                <div class="trainings-row">
                                    <span class="trainings-label">Trainings affected:</span>
                                    <span class="trainings-value"><?= (int)$extRequest['trainings_affected'] ?></span>
                                </div>
                            </div>

                            <?php if (!empty($extRequest['reason'])): ?>
                                <div class="request-reason">
                                    <span class="reason-label">Reason:</span>
                                    <p class="reason-text"><?= e($extRequest['reason']) ?></p>
                                </div>
                            <?php endif; ?>

                            <div class="request-actions">
                                <a href="<?= url("/leave/extended/{$extRequest['id']}") ?>" class="btn btn-sm btn-outline">
                                    View Details
                                </a>
                                <?php if ($isCFO): ?>
                                    <form method="POST" action="<?= url("/leave/extended/{$extRequest['id']}/deny") ?>" class="inline-form" onsubmit="return confirm('Are you sure you want to deny this extended leave request?');">
                                        <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                                        <button type="submit" class="btn btn-sm deny-btn">Deny</button>
                                    </form>
                                    <form method="POST" action="<?= url("/leave/extended/{$extRequest['id']}/approve") ?>" class="inline-form" onsubmit="return confirm('Are you sure you want to approve this extended leave request?');">
                                        <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                                        <button type="submit" class="btn btn-sm btn-primary approve-btn">Approve</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<!-- Approve Confirmation Modal -->
<div id="approve-modal" class="modal" hidden>
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Approve Leave Request</h2>
            <button type="button" class="modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <p>Approve leave request for <strong id="approve-member-name"></strong>?</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn" onclick="Leave.closeApproveModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="confirm-approve-btn" onclick="Leave.confirmApprove()">
                Approve
            </button>
        </div>
    </div>
</div>

<!-- Deny Confirmation Modal -->
<div id="deny-modal" class="modal" hidden>
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Deny Leave Request</h2>
            <button type="button" class="modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <p>Deny leave request for <strong id="deny-member-name"></strong> on <strong id="deny-date-text"></strong>?</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn" onclick="Leave.closeDenyModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="confirm-deny-btn" style="background: var(--color-error); border-color: var(--color-error);" onclick="Leave.confirmDeny()">
                Deny
            </button>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();

// Add extra scripts
$extraScripts = '<script src="' . url('/assets/js/leave.js') . '"></script>';

// Include main layout
require __DIR__ . '/../../layouts/main.php';
