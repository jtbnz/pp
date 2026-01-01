<?php
declare(strict_types=1);

/**
 * Pending Leave Requests Page (Officers View)
 *
 * Shows all pending leave requests for the brigade.
 * Variables available:
 * - $pendingRequests: array of pending leave requests
 * - $groupedRequests: requests grouped by training date
 * - $pendingCount: total pending requests
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
            <?php if ($pendingCount > 0): ?>
                <span class="pending-badge"><?= $pendingCount ?> pending</span>
            <?php endif; ?>
        </header>

        <?php if (empty($pendingRequests)): ?>
            <div class="card">
                <div class="card-body text-center p-5">
                    <p class="empty-icon">&#9989;</p>
                    <p class="text-secondary mb-0">No pending leave requests to review.</p>
                </div>
            </div>
        <?php else: ?>
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
