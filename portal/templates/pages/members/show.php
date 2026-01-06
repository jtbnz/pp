<?php
declare(strict_types=1);

/**
 * Member Profile Page Template
 *
 * Displays member profile with service history.
 *
 * Variables:
 * - $member: Member data
 * - $servicePeriods: Array of service periods
 * - $serviceInfo: Calculated service info
 * - $canEdit: Whether current user can edit this member
 * - $isOwnProfile: Whether viewing own profile
 */

global $config;

$pageTitle = $pageTitle ?? $member['name'];
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';
$user = currentUser();

// Flash messages from session
$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// Start output buffering for content
ob_start();
?>

<div class="page-member-profile">
    <div class="page-header">
        <div class="page-header-content">
            <div class="page-header-back">
                <?php if (hasRole('admin')): ?>
                    <a href="<?= url('/members') ?>" class="btn-back" aria-label="Back to members">
                        <span class="back-icon">&larr;</span>
                    </a>
                <?php endif; ?>
                <h1><?= e($member['name']) ?></h1>
            </div>
            <?php if ($canEdit): ?>
                <a href="<?= url('/members/' . $member['id'] . '/edit') ?>" class="btn btn-secondary">Edit</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flashMessage): ?>
        <div class="flash-message flash-<?= e($flashType) ?>">
            <?= e($flashMessage) ?>
            <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Profile Card -->
    <div class="card profile-card mb-4">
        <div class="profile-header">
            <div class="profile-avatar">
                <?= strtoupper(substr($member['name'], 0, 1)) ?>
            </div>
            <div class="profile-info">
                <h2 class="profile-name"><?= e($member['name']) ?></h2>
                <p class="profile-email"><?= e($member['email']) ?></p>
                <?php if ($member['phone']): ?>
                    <p class="profile-phone"><?= e($member['phone']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="profile-details">
            <div class="detail-row">
                <span class="detail-label">Role</span>
                <span class="detail-value badge badge-<?= $member['role'] ?>">
                    <?= e(Member::getRoleDisplayName($member['role'])) ?>
                </span>
            </div>

            <?php if ($member['rank']): ?>
                <div class="detail-row">
                    <span class="detail-label">Rank</span>
                    <span class="detail-value">
                        <?= e($member['rank']) ?> - <?= e(Member::getRankDisplayName($member['rank'])) ?>
                    </span>
                </div>

                <?php if ($member['rank_date']): ?>
                    <div class="detail-row">
                        <span class="detail-label">Rank Since</span>
                        <span class="detail-value"><?= date('j F Y', strtotime($member['rank_date'])) ?></span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="detail-row">
                <span class="detail-label">Status</span>
                <span class="detail-value">
                    <span class="status-indicator status-<?= $member['status'] ?>">
                        <?= ucfirst($member['status']) ?>
                    </span>
                </span>
            </div>

            <?php if ($member['access_expires']): ?>
                <div class="detail-row">
                    <span class="detail-label">Access Expires</span>
                    <span class="detail-value"><?= date('j F Y', strtotime($member['access_expires'])) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($member['last_login_at']): ?>
                <div class="detail-row">
                    <span class="detail-label">Last Login</span>
                    <span class="detail-value"><?= timeAgo($member['last_login_at']) ?></span>
                </div>
            <?php endif; ?>

            <div class="detail-row">
                <span class="detail-label">Member Since</span>
                <span class="detail-value"><?= date('j F Y', strtotime($member['created_at'])) ?></span>
            </div>
        </div>
    </div>

    <!-- Attendance Card -->
    <div class="card attendance-card mb-4">
        <div class="card-header">
            <h3>Attendance</h3>
        </div>
        <div class="card-body">
            <div class="attendance-container" data-member-id="<?= $member['id'] ?>">
                <div class="attendance-loading">
                    <div class="spinner"></div>
                    <p>Loading attendance data...</p>
                </div>
            </div>
        </div>
    </div>

    <?php if ($isOwnProfile): ?>
    <!-- Push Notifications Card -->
    <div class="card notifications-card mb-4">
        <div class="card-header">
            <h3>Push Notifications</h3>
        </div>
        <div class="card-body">
            <p class="text-secondary mb-3">
                Receive instant notifications for leave request updates, urgent notices, and other important alerts.
            </p>
            <div class="notification-controls">
                <button type="button" id="push-toggle" class="btn btn-primary">
                    Enable Notifications
                </button>
                <p id="push-status" class="push-status text-secondary mt-2"></p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Service History Card -->
    <div class="card service-card">
        <div class="card-header">
            <h3>Service History</h3>
            <?php if ($canEdit): ?>
                <button type="button" class="btn btn-sm btn-secondary" onclick="toggleAddPeriodForm()">
                    Add Period
                </button>
            <?php endif; ?>
        </div>

        <div class="card-body">
            <!-- Total Service Summary -->
            <div class="service-summary">
                <div class="service-total">
                    <span class="service-label">Total Service</span>
                    <span class="service-value"><?= e($serviceInfo['display']) ?></span>
                </div>
                <div class="service-days">
                    <?= number_format($serviceInfo['total_days']) ?> days
                </div>
            </div>

            <?php if ($canEdit): ?>
                <!-- Add Service Period Form (hidden by default) -->
                <form id="add-period-form" action="<?= url('/members/' . $member['id'] . '/service-periods') ?>" method="POST" class="service-period-form" hidden>
                    <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="start_date">Start Date</label>
                            <input type="date" id="start_date" name="start_date" class="form-input" required>
                        </div>

                        <div class="form-group">
                            <label for="end_date">End Date</label>
                            <input type="date" id="end_date" name="end_date" class="form-input">
                            <small class="form-hint">Leave empty if currently serving</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="notes">Notes</label>
                        <input type="text" id="notes" name="notes" class="form-input" placeholder="Reason for gap, etc.">
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Add Period</button>
                        <button type="button" class="btn btn-text" onclick="toggleAddPeriodForm()">Cancel</button>
                    </div>
                </form>
            <?php endif; ?>

            <!-- Service Periods Table -->
            <?php if (empty($servicePeriods)): ?>
                <p class="text-secondary text-center p-3">No service periods recorded.</p>
            <?php else: ?>
                <table class="service-periods-table">
                    <thead>
                        <tr>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Duration</th>
                            <th>Notes</th>
                            <?php if ($canEdit): ?>
                                <th class="actions-col">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($servicePeriods as $period): ?>
                            <?php include __DIR__ . '/../../partials/service-period-row.php'; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canEdit && $member['id'] !== $user['id'] && $member['status'] === 'active'): ?>
        <!-- Danger Zone -->
        <div class="card danger-zone mt-4">
            <div class="card-header">
                <h3>Danger Zone</h3>
            </div>
            <div class="card-body">
                <div class="danger-action">
                    <div class="danger-info">
                        <strong>Deactivate Member</strong>
                        <p class="text-secondary">This will revoke access and hide this member from active lists.</p>
                    </div>
                    <form action="<?= url('/members/' . $member['id']) ?>" method="POST" onsubmit="return confirm('Are you sure you want to deactivate this member?');">
                        <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="_method" value="DELETE">
                        <button type="submit" class="btn btn-danger">Deactivate</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.page-member-profile {
    padding: 1rem;
    max-width: 800px;
    margin: 0 auto;
}

.page-header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.page-header-back {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.btn-back {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--bg-secondary, #f5f5f5);
    text-decoration: none;
    color: var(--text-color, #333);
    font-size: 1.25rem;
}

.btn-back:hover {
    background: var(--bg-hover, #eee);
}

.page-header h1 {
    margin: 0;
    font-size: 1.5rem;
}

.profile-card {
    overflow: hidden;
}

.profile-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.5rem;
    background: linear-gradient(135deg, var(--primary-color, #D32F2F), var(--primary-dark, #B71C1C));
    color: white;
}

.profile-avatar {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    font-weight: bold;
}

.profile-info {
    flex: 1;
}

.profile-name {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
}

.profile-email,
.profile-phone {
    margin: 0.25rem 0 0;
    opacity: 0.9;
    font-size: 0.875rem;
}

.profile-details {
    padding: 1rem;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 0;
    border-bottom: 1px solid var(--border-color, #eee);
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    color: var(--text-secondary, #666);
    font-size: 0.875rem;
}

.detail-value {
    font-weight: 500;
}

.badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 500;
    text-transform: uppercase;
}

.badge-firefighter { background: #e3f2fd; color: #1565c0; }
.badge-officer { background: #fff3e0; color: #ef6c00; }
.badge-admin { background: #f3e5f5; color: #7b1fa2; }
.badge-superadmin { background: #ffebee; color: #c62828; }

.status-indicator {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.status-indicator::before {
    content: '';
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.status-active::before { background: #4caf50; }
.status-inactive::before { background: #9e9e9e; }

.service-card .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    border-bottom: 1px solid var(--border-color, #eee);
}

.service-card .card-header h3 {
    margin: 0;
    font-size: 1.125rem;
}

.service-summary {
    background: var(--bg-secondary, #f9f9f9);
    padding: 1rem;
    border-radius: 8px;
    text-align: center;
    margin-bottom: 1rem;
}

.service-total {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.service-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    color: var(--text-secondary, #666);
}

.service-value {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--primary-color, #D32F2F);
}

.service-days {
    font-size: 0.875rem;
    color: var(--text-secondary, #666);
    margin-top: 0.25rem;
}

.service-period-form {
    background: var(--bg-secondary, #f9f9f9);
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    margin-bottom: 0.25rem;
}

.form-hint {
    display: block;
    font-size: 0.75rem;
    color: var(--text-secondary, #666);
    margin-top: 0.25rem;
}

.form-actions {
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
}

.service-periods-table {
    width: 100%;
    border-collapse: collapse;
}

.service-periods-table th,
.service-periods-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color, #eee);
}

.service-periods-table th {
    font-size: 0.75rem;
    text-transform: uppercase;
    color: var(--text-secondary, #666);
    font-weight: 500;
}

.actions-col {
    width: 100px;
    text-align: right;
}

.danger-zone {
    border-color: #ffcdd2;
}

.danger-zone .card-header {
    background: #ffebee;
    border-bottom: 1px solid #ffcdd2;
    padding: 0.75rem 1rem;
}

.danger-zone .card-header h3 {
    margin: 0;
    color: #c62828;
    font-size: 1rem;
}

.danger-action {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
}

.danger-info p {
    margin: 0.25rem 0 0;
    font-size: 0.875rem;
}

.btn-danger {
    background: #c62828;
    color: white;
    border-color: #c62828;
}

.btn-danger:hover {
    background: #b71c1c;
}

.notifications-card .card-header {
    padding: 1rem;
    border-bottom: 1px solid var(--border-color, #eee);
}

.notifications-card .card-header h3 {
    margin: 0;
    font-size: 1.125rem;
}

.notifications-card .card-body {
    padding: 1rem;
}

.notification-controls {
    text-align: center;
}

.push-status {
    font-size: 0.875rem;
    min-height: 1.5em;
}

#push-toggle.subscribed {
    background: var(--success, #4caf50);
    border-color: var(--success, #4caf50);
}

#push-toggle:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

@media (max-width: 600px) {
    .profile-header {
        flex-direction: column;
        text-align: center;
    }

    .detail-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.25rem;
    }

    .service-periods-table {
        font-size: 0.875rem;
    }

    .service-periods-table th:nth-child(4),
    .service-periods-table td:nth-child(4) {
        display: none;
    }

    .danger-action {
        flex-direction: column;
        align-items: stretch;
        text-align: center;
    }
}
</style>

<script>
function toggleAddPeriodForm() {
    const form = document.getElementById('add-period-form');
    form.hidden = !form.hidden;
    if (!form.hidden) {
        form.querySelector('input[name="start_date"]').focus();
    }
}
</script>

<?php
$content = ob_get_clean();

// Include attendance.js always, plus push.js for own profile
$extraScripts = '<script src="' . url('/assets/js/attendance.js') . '"></script>';
if ($isOwnProfile) {
    $extraScripts .= '<script src="' . url('/assets/js/push.js') . '"></script>';
}

// Include main layout
require __DIR__ . '/../../layouts/main.php';
