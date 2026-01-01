<?php
declare(strict_types=1);

/**
 * Leave Request Detail Page
 *
 * Shows details of a single leave request.
 * Variables available:
 * - $leaveRequest: array with leave request data
 * - $isOwner: bool - is current user the request owner
 * - $isOfficer: bool - is current user an officer
 * - $canApprove: bool - can officer approve/deny this request
 */

global $config;

$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';

// Status display helpers
$statusLabels = [
    'pending' => 'Pending',
    'approved' => 'Approved',
    'denied' => 'Denied',
    'cancelled' => 'Cancelled',
];

$statusClasses = [
    'pending' => 'status-pending',
    'approved' => 'status-approved',
    'denied' => 'status-denied',
    'cancelled' => 'status-cancelled',
];

$status = $leaveRequest['status'] ?? 'pending';
$statusLabel = $statusLabels[$status] ?? ucfirst($status);
$statusClass = $statusClasses[$status] ?? '';

// Format dates
$trainingDate = date('l, j F Y', strtotime($leaveRequest['training_date']));
$requestedAt = date('j M Y \a\t g:i a', strtotime($leaveRequest['requested_at']));
$decidedAt = !empty($leaveRequest['decided_at'])
    ? date('j M Y \a\t g:i a', strtotime($leaveRequest['decided_at']))
    : null;

// Start output buffering for content
ob_start();
?>

<div class="page-leave-show">
    <div class="container">
        <!-- Back Link -->
        <nav class="breadcrumb mb-4">
            <a href="<?= url('/leave') ?>" class="breadcrumb-link">
                <span>&larr;</span> Back to Leave Requests
            </a>
        </nav>

        <!-- Request Card -->
        <div class="card leave-detail-card">
            <div class="card-header">
                <h1>Leave Request</h1>
                <span class="status-badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
            </div>

            <div class="card-body">
                <!-- Training Date -->
                <div class="detail-row">
                    <label>Training Date</label>
                    <span class="detail-value"><?= e($trainingDate) ?></span>
                </div>

                <!-- Requested By -->
                <div class="detail-row">
                    <label>Requested By</label>
                    <span class="detail-value"><?= e($leaveRequest['member_name'] ?? 'Unknown') ?></span>
                </div>

                <!-- Requested At -->
                <div class="detail-row">
                    <label>Requested</label>
                    <span class="detail-value"><?= e($requestedAt) ?></span>
                </div>

                <!-- Reason -->
                <?php if (!empty($leaveRequest['reason'])): ?>
                <div class="detail-row">
                    <label>Reason</label>
                    <span class="detail-value"><?= e($leaveRequest['reason']) ?></span>
                </div>
                <?php endif; ?>

                <!-- Decision Info (if decided) -->
                <?php if ($decidedAt): ?>
                <hr>
                <div class="detail-row">
                    <label><?= $status === 'approved' ? 'Approved' : 'Decided' ?> By</label>
                    <span class="detail-value"><?= e($leaveRequest['decided_by_name'] ?? 'Unknown') ?></span>
                </div>
                <div class="detail-row">
                    <label>Decision Date</label>
                    <span class="detail-value"><?= e($decidedAt) ?></span>
                </div>
                <?php endif; ?>

                <!-- DLB Sync Status -->
                <?php if ($status === 'approved'): ?>
                <div class="detail-row">
                    <label>Synced to DLB</label>
                    <span class="detail-value">
                        <?php if ($leaveRequest['synced_to_dlb'] ?? false): ?>
                            <span class="text-success">&#10003; Yes</span>
                        <?php else: ?>
                            <span class="text-secondary">Pending sync</span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Actions -->
            <?php if ($canApprove || ($isOwner && $status === 'pending')): ?>
            <div class="card-footer">
                <div class="action-buttons">
                    <?php if ($canApprove): ?>
                        <form method="POST" action="<?= url('/leave/' . (int)$leaveRequest['id'] . '/approve') ?>" class="inline-form">
                            <input type="hidden" name="_csrf_token" value="<?= e(csrfToken()) ?>">
                            <button type="submit" class="btn btn-success">
                                <span class="btn-icon">&#10003;</span>
                                Approve
                            </button>
                        </form>
                        <form method="POST" action="<?= url('/leave/' . (int)$leaveRequest['id'] . '/deny') ?>" class="inline-form">
                            <input type="hidden" name="_csrf_token" value="<?= e(csrfToken()) ?>">
                            <button type="submit" class="btn btn-danger">
                                <span class="btn-icon">&#10007;</span>
                                Deny
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($isOwner && $status === 'pending'): ?>
                        <form method="POST" action="<?= url('/leave/' . (int)$leaveRequest['id'] . '/cancel') ?>" class="inline-form">
                            <input type="hidden" name="_csrf_token" value="<?= e(csrfToken()) ?>">
                            <button type="submit" class="btn btn-outline" onclick="return confirm('Cancel this leave request?')">
                                Cancel Request
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.page-leave-show .breadcrumb {
    padding: 0;
}

.page-leave-show .breadcrumb-link {
    color: var(--color-primary);
    text-decoration: none;
}

.page-leave-show .leave-detail-card {
    max-width: 600px;
    margin: 0 auto;
}

.page-leave-show .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.page-leave-show .card-header h1 {
    margin: 0;
    font-size: 1.25rem;
}

.page-leave-show .status-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 1rem;
    font-size: 0.875rem;
    font-weight: 500;
}

.page-leave-show .status-pending {
    background: var(--color-warning-light, #fff3e0);
    color: var(--color-warning-dark, #e65100);
}

.page-leave-show .status-approved {
    background: var(--color-success-light, #e8f5e9);
    color: var(--color-success-dark, #2e7d32);
}

.page-leave-show .status-denied {
    background: var(--color-danger-light, #ffebee);
    color: var(--color-danger-dark, #c62828);
}

.page-leave-show .status-cancelled {
    background: var(--color-secondary-light, #f5f5f5);
    color: var(--color-secondary, #666);
}

.page-leave-show .detail-row {
    display: flex;
    justify-content: space-between;
    padding: 0.75rem 0;
    border-bottom: 1px solid var(--color-border, #eee);
}

.page-leave-show .detail-row:last-child {
    border-bottom: none;
}

.page-leave-show .detail-row label {
    font-weight: 500;
    color: var(--color-secondary, #666);
}

.page-leave-show .detail-value {
    text-align: right;
}

.page-leave-show .card-footer {
    border-top: 1px solid var(--color-border, #eee);
    padding-top: 1rem;
}

.page-leave-show .action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.page-leave-show .inline-form {
    display: inline;
}
</style>

<?php
$content = ob_get_clean();

// Include the main layout
require __DIR__ . '/../../layouts/main.php';
?>
