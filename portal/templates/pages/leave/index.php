<?php
declare(strict_types=1);

/**
 * Leave Requests Index Page
 *
 * Shows the member's leave requests and upcoming trainings.
 * Variables available:
 * - $leaveRequests: array of member's leave requests
 * - $upcomingTrainings: array of trainings available for leave request
 * - $activeCount: number of pending/approved requests
 * - $maxPending: maximum allowed pending requests
 * - $canRequestMore: bool - can member request more leave
 * - $isOfficer: bool - is user an officer
 */

global $config;

$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';

// Start output buffering for content
ob_start();
?>

<div class="page-leave">
    <div class="container">
        <!-- Page Header -->
        <header class="page-header">
            <h1>Leave Requests</h1>
            <?php if ($isOfficer): ?>
                <a href="/leave/pending" class="btn btn-outline btn-sm">
                    <span class="btn-icon">&#9989;</span>
                    View Pending Approvals
                </a>
            <?php endif; ?>
        </header>

        <!-- Leave Limit Info -->
        <div class="leave-limit-info card mb-4">
            <div class="card-body">
                <div class="limit-status">
                    <span class="limit-label">Active Requests:</span>
                    <span class="limit-value <?= $activeCount >= $maxPending ? 'at-limit' : '' ?>">
                        <?= $activeCount ?> / <?= $maxPending ?>
                    </span>
                </div>
                <p class="limit-hint text-secondary">
                    You can have up to <?= $maxPending ?> pending or approved leave requests at a time.
                </p>
            </div>
        </div>

        <!-- Upcoming Trainings Section -->
        <?php if ($canRequestMore && !empty($upcomingTrainings)): ?>
        <section class="upcoming-trainings mb-4">
            <h2 class="section-title">Request Leave</h2>
            <div class="training-list">
                <?php foreach ($upcomingTrainings as $training): ?>
                    <?php include __DIR__ . '/../../partials/training-row.php'; ?>
                <?php endforeach; ?>
            </div>
        </section>
        <?php elseif (!$canRequestMore): ?>
        <section class="upcoming-trainings mb-4">
            <div class="card">
                <div class="card-body text-center">
                    <p class="text-warning mb-2">
                        <span style="font-size: 2rem;">&#9888;</span>
                    </p>
                    <p class="mb-0">
                        You've reached the maximum of <?= $maxPending ?> active leave requests.
                        <br>
                        Cancel an existing request or wait for one to pass before requesting more.
                    </p>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Your Leave Requests Section -->
        <section class="leave-requests-section">
            <h2 class="section-title">Your Requests</h2>

            <?php if (empty($leaveRequests)): ?>
                <div class="card">
                    <div class="card-body text-center p-4">
                        <p class="text-secondary mb-0">You haven't made any leave requests yet.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="leave-requests-list">
                    <?php
                    // Separate requests by status
                    $upcoming = array_filter($leaveRequests, fn($r) => strtotime($r['training_date']) >= strtotime('today') && $r['status'] === 'pending');
                    $approved = array_filter($leaveRequests, fn($r) => strtotime($r['training_date']) >= strtotime('today') && $r['status'] === 'approved');
                    $denied = array_filter($leaveRequests, fn($r) => strtotime($r['training_date']) >= strtotime('today') && $r['status'] === 'denied');
                    $past = array_filter($leaveRequests, fn($r) => strtotime($r['training_date']) < strtotime('today'));
                    ?>

                    <?php if (!empty($upcoming)): ?>
                        <h3 class="status-heading pending">Pending</h3>
                        <?php foreach ($upcoming as $request): ?>
                            <?php include __DIR__ . '/../../partials/leave-request-card.php'; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($approved)): ?>
                        <h3 class="status-heading approved">Approved</h3>
                        <?php foreach ($approved as $request): ?>
                            <?php include __DIR__ . '/../../partials/leave-request-card.php'; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($denied)): ?>
                        <h3 class="status-heading denied">Denied</h3>
                        <?php foreach ($denied as $request): ?>
                            <?php include __DIR__ . '/../../partials/leave-request-card.php'; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($past)): ?>
                        <details class="past-requests">
                            <summary class="status-heading past">
                                Past Requests (<?= count($past) ?>)
                            </summary>
                            <?php foreach ($past as $request): ?>
                                <?php include __DIR__ . '/../../partials/leave-request-card.php'; ?>
                            <?php endforeach; ?>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<!-- Leave Request Modal -->
<div id="leave-modal" class="modal" hidden>
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Request Leave</h2>
            <button type="button" class="modal-close" aria-label="Close">&times;</button>
        </div>
        <form id="leave-request-form" method="POST" action="/leave">
            <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="training_date" id="leave-training-date">

            <div class="modal-body">
                <p class="leave-date-display">
                    <strong id="leave-date-text"></strong>
                </p>

                <div class="form-group">
                    <label for="leave-reason" class="form-label">Reason (optional)</label>
                    <textarea
                        id="leave-reason"
                        name="reason"
                        class="form-textarea"
                        rows="3"
                        placeholder="Enter reason for leave..."
                    ></textarea>
                    <span class="form-hint">This will be visible to officers reviewing your request.</span>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn" onclick="Leave.closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Submit Request</button>
            </div>
        </form>
    </div>
</div>

<!-- Cancel Confirmation Modal -->
<div id="cancel-modal" class="modal" hidden>
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Cancel Leave Request</h2>
            <button type="button" class="modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to cancel your leave request for <strong id="cancel-date-text"></strong>?</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn" onclick="Leave.closeCancelModal()">Keep Request</button>
            <form id="cancel-form" method="POST" action="" style="display: inline;">
                <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="_method" value="DELETE">
                <button type="submit" class="btn btn-primary" style="background: var(--color-error); border-color: var(--color-error);">
                    Cancel Request
                </button>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();

// Add extra scripts
$extraScripts = '<script src="/assets/js/leave.js"></script>';

// Include main layout
require __DIR__ . '/../../layouts/main.php';
