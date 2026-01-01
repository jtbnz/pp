<?php
declare(strict_types=1);

/**
 * Admin Leave Requests Template
 *
 * Leave requests list with status filter and approval actions.
 */

global $config;

$pageTitle = $pageTitle ?? 'Leave Requests';
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';
$user = currentUser();

// Get flash messages
$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// Start output buffering for content
ob_start();
?>

<div class="page-admin-leave">
    <header class="page-header">
        <div class="header-row">
            <div>
                <h1>Leave Requests</h1>
                <p class="text-secondary"><?= count($leaveRequests) ?> request<?= count($leaveRequests) !== 1 ? 's' : '' ?></p>
            </div>
        </div>
    </header>

    <?php if ($flashMessage): ?>
    <div class="flash-message flash-<?= e($flashType) ?>">
        <?= e($flashMessage) ?>
        <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Filter Section -->
    <section class="filters-section mb-3">
        <form method="GET" action="<?= url('/admin/leave') ?>" class="filters-form">
            <div class="filter-group">
                <label for="status" class="form-label-inline">Status:</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All</option>
                    <option value="pending" <?= ($status ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="approved" <?= ($status ?? '') === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="denied" <?= ($status ?? '') === 'denied' ? 'selected' : '' ?>>Denied</option>
                </select>
            </div>
            <div class="filter-group">
                <label for="from" class="form-label-inline">From:</label>
                <input type="date" id="from" name="from" class="form-input"
                       value="<?= e($from) ?>">
            </div>
            <div class="filter-group">
                <label for="to" class="form-label-inline">To:</label>
                <input type="date" id="to" name="to" class="form-input"
                       value="<?= e($to) ?>">
            </div>
            <button type="submit" class="btn btn-secondary">Filter</button>
        </form>
    </section>

    <!-- Leave Requests List -->
    <section class="leave-list">
        <?php if (empty($leaveRequests)): ?>
        <div class="card">
            <div class="card-body text-center p-4">
                <p class="text-secondary">No leave requests found for this period</p>
            </div>
        </div>
        <?php else: ?>
        <div class="leave-requests-table">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Training Date</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Requested</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leaveRequests as $leave): ?>
                    <tr class="leave-row leave-<?= e($leave['status']) ?>">
                        <td class="member-name">
                            <strong><?= e($leave['member_name']) ?></strong>
                        </td>
                        <td class="training-date">
                            <?php
                            $trainingDate = new DateTime($leave['training_date']);
                            ?>
                            <span class="date"><?= $trainingDate->format('D, j M Y') ?></span>
                        </td>
                        <td class="reason">
                            <?= e($leave['reason'] ?? 'No reason provided') ?>
                        </td>
                        <td class="status">
                            <span class="badge badge-<?= e($leave['status']) ?>">
                                <?= ucfirst(e($leave['status'])) ?>
                            </span>
                            <?php if ($leave['status'] !== 'pending' && !empty($leave['decided_by_name'])): ?>
                            <span class="approver text-secondary">
                                by <?= e($leave['decided_by_name']) ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="requested-date">
                            <?= (new DateTime($leave['requested_at']))->format('j M Y') ?>
                        </td>
                        <td class="actions">
                            <?php if ($leave['status'] === 'pending'): ?>
                            <form action="<?= url('/leave/' . $leave['id'] . '/approve') ?>" method="POST" class="inline-form">
                                <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                                <button type="submit" class="btn btn-sm btn-success">Approve</button>
                            </form>
                            <form action="<?= url('/leave/' . $leave['id'] . '/deny') ?>" method="POST" class="inline-form">
                                <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Deny</button>
                            </form>
                            <?php else: ?>
                            <span class="text-secondary">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Card View -->
        <div class="leave-cards-mobile">
            <?php foreach ($leaveRequests as $leave): ?>
            <div class="leave-card card leave-<?= e($leave['status']) ?>">
                <div class="leave-card-header">
                    <strong><?= e($leave['member_name']) ?></strong>
                    <span class="badge badge-<?= e($leave['status']) ?>">
                        <?= ucfirst(e($leave['status'])) ?>
                    </span>
                </div>
                <div class="leave-card-body">
                    <div class="leave-detail">
                        <span class="label">Training:</span>
                        <?php $trainingDateMobile = new DateTime($leave['training_date']); ?>
                        <span class="value"><?= $trainingDateMobile->format('D, j M Y') ?></span>
                    </div>
                    <?php if ($leave['reason']): ?>
                    <div class="leave-detail">
                        <span class="label">Reason:</span>
                        <span class="value"><?= e($leave['reason']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="leave-detail">
                        <span class="label">Requested:</span>
                        <span class="value"><?= (new DateTime($leave['requested_at']))->format('j M Y') ?></span>
                    </div>
                    <?php if ($leave['status'] !== 'pending' && !empty($leave['decided_by_name'])): ?>
                    <div class="leave-detail">
                        <span class="label"><?= ucfirst(e($leave['status'])) ?> by:</span>
                        <span class="value"><?= e($leave['decided_by_name']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($leave['status'] === 'pending'): ?>
                <div class="leave-card-actions">
                    <form action="<?= url('/leave/' . $leave['id'] . '/approve') ?>" method="POST" class="inline-form">
                        <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                        <button type="submit" class="btn btn-success">Approve</button>
                    </form>
                    <form action="<?= url('/leave/' . $leave['id'] . '/deny') ?>" method="POST" class="inline-form">
                        <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                        <button type="submit" class="btn btn-danger">Deny</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <div class="admin-nav-back mt-4">
        <a href="<?= url('/admin') ?>" class="btn btn-text">&larr; Back to Dashboard</a>
    </div>
</div>

<style>
.header-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.filters-form {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.form-label-inline {
    font-weight: 500;
    white-space: nowrap;
}

.filter-group .form-input,
.filter-group .form-select {
    width: auto;
}

/* Table view for desktop */
.data-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--card-bg, white);
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.data-table th,
.data-table td {
    padding: 0.75rem 1rem;
    text-align: left;
    border-bottom: 1px solid var(--border, #e0e0e0);
}

.data-table th {
    background: var(--bg-secondary, #f5f5f5);
    font-weight: 600;
    font-size: 0.875rem;
}

.data-table .event-title {
    display: block;
    font-size: 0.75rem;
}

.data-table .approver {
    display: block;
    font-size: 0.75rem;
}

.leave-row.leave-pending {
    background: rgba(255, 193, 7, 0.1);
}

.leave-row.leave-approved {
    background: rgba(76, 175, 80, 0.05);
}

.leave-row.leave-denied {
    background: rgba(211, 47, 47, 0.05);
}

/* Badges */
.badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 4px;
}

.badge-pending {
    background: #FFC107;
    color: #000;
}

.badge-approved {
    background: #4CAF50;
    color: white;
}

.badge-denied {
    background: var(--error, #D32F2F);
    color: white;
}

/* Buttons */
.inline-form {
    display: inline;
}

.btn-success {
    background: #4CAF50;
    color: white;
}

.btn-success:hover {
    background: #388E3C;
}

.btn-danger {
    background: var(--error, #D32F2F);
    color: white;
}

.btn-danger:hover {
    background: #B71C1C;
}

.actions {
    display: flex;
    gap: 0.5rem;
}

/* Mobile card view */
.leave-cards-mobile {
    display: none;
}

.leave-card {
    padding: 1rem;
    margin-bottom: 0.75rem;
}

.leave-card.leave-pending {
    border-left: 4px solid #FFC107;
}

.leave-card.leave-approved {
    border-left: 4px solid #4CAF50;
}

.leave-card.leave-denied {
    border-left: 4px solid var(--error, #D32F2F);
}

.leave-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}

.leave-card-body {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.leave-detail {
    display: flex;
    gap: 0.5rem;
    font-size: 0.875rem;
}

.leave-detail .label {
    color: var(--text-secondary);
    min-width: 80px;
}

.leave-card-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border, #e0e0e0);
}

.leave-card-actions .btn {
    flex: 1;
}

@media (max-width: 800px) {
    .leave-requests-table {
        display: none;
    }

    .leave-cards-mobile {
        display: block;
    }
}
</style>

<script>
// Dismiss flash messages
document.querySelectorAll('.flash-dismiss').forEach(btn => {
    btn.addEventListener('click', function() {
        this.closest('.flash-message').remove();
    });
});
</script>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../../layouts/main.php';
?>
