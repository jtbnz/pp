<?php
declare(strict_types=1);

/**
 * Admin Edit Member Template
 *
 * Form to edit an existing member's details.
 */

global $config;

$pageTitle = $pageTitle ?? 'Edit Member';
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';
$user = currentUser();

// Get form errors and old data
$formErrors = $_SESSION['form_errors'] ?? [];
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['form_data']);

// Get flash messages
$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// Use form data if available, otherwise use member data
$memberData = !empty($formData) ? array_merge($member, $formData) : $member;

// Start output buffering for content
ob_start();
?>

<div class="page-admin-edit-member">
    <header class="page-header">
        <h1>Edit Member</h1>
        <p class="text-secondary"><?= e($member['name']) ?></p>
    </header>

    <?php if ($flashMessage): ?>
    <div class="flash-message flash-<?= e($flashType) ?>">
        <?= e($flashMessage) ?>
        <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Member Info Card -->
    <div class="member-summary card mb-4">
        <div class="card-body">
            <div class="member-summary-content">
                <span class="member-avatar large"><?= strtoupper(substr($member['name'], 0, 1)) ?></span>
                <div class="member-details">
                    <h2><?= e($member['name']) ?></h2>
                    <p class="text-secondary"><?= e($member['email']) ?></p>
                    <?php if ($member['last_login_at']): ?>
                    <p class="text-secondary small">Last login: <?= timeAgo($member['last_login_at']) ?></p>
                    <?php else: ?>
                    <p class="text-secondary small">Never logged in</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Form -->
    <div class="card">
        <form method="POST" action="<?= url('/admin/members/' . $member['id']) ?>" class="form">
            <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="_method" value="PUT">

            <div class="form-group <?= isset($formErrors['name']) ? 'has-error' : '' ?>">
                <label for="name" class="form-label">Full Name *</label>
                <input type="text" id="name" name="name" class="form-input"
                       value="<?= e($memberData['name'] ?? '') ?>" required>
                <?php if (isset($formErrors['name'])): ?>
                <span class="form-error"><?= e($formErrors['name']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group <?= isset($formErrors['email']) ? 'has-error' : '' ?>">
                <label for="email" class="form-label">Email Address *</label>
                <input type="email" id="email" name="email" class="form-input"
                       value="<?= e($memberData['email'] ?? '') ?>" required>
                <?php if (isset($formErrors['email'])): ?>
                <span class="form-error"><?= e($formErrors['email']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="phone" class="form-label">Phone Number</label>
                <input type="tel" id="phone" name="phone" class="form-input"
                       value="<?= e($memberData['phone'] ?? '') ?>"
                       placeholder="021 123 4567">
            </div>

            <div class="form-row">
                <div class="form-group <?= isset($formErrors['role']) ? 'has-error' : '' ?>">
                    <label for="role" class="form-label">Role *</label>
                    <select id="role" name="role" class="form-select" required>
                        <?php foreach ($roles as $role): ?>
                        <?php if ($role !== 'superadmin' || hasRole('superadmin')): ?>
                        <option value="<?= e($role) ?>" <?= ($memberData['role'] ?? '') === $role ? 'selected' : '' ?>>
                            <?= e(Member::getRoleDisplayName($role)) ?>
                        </option>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($formErrors['role'])): ?>
                    <span class="form-error"><?= e($formErrors['role']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group <?= isset($formErrors['rank']) ? 'has-error' : '' ?>">
                    <label for="rank" class="form-label">Rank</label>
                    <select id="rank" name="rank" class="form-select">
                        <option value="">No rank</option>
                        <?php foreach ($ranks as $rank): ?>
                        <option value="<?= e($rank) ?>" <?= ($memberData['rank'] ?? '') === $rank ? 'selected' : '' ?>>
                            <?= e($rank) ?> - <?= e(Member::getRankDisplayName($rank)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($formErrors['rank'])): ?>
                    <span class="form-error"><?= e($formErrors['rank']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group <?= isset($formErrors['status']) ? 'has-error' : '' ?>">
                <label for="status" class="form-label">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="active" <?= ($memberData['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($memberData['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
                <?php if (isset($formErrors['status'])): ?>
                <span class="form-error"><?= e($formErrors['status']) ?></span>
                <?php endif; ?>
                <span class="form-hint">Inactive members cannot log in</span>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="<?= url('/admin/members') ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <!-- Service History -->
    <section class="service-history mt-4">
        <h2>Service History</h2>
        <div class="card">
            <div class="card-body">
                <p class="service-total">
                    <strong>Total Service:</strong> <?= e($serviceInfo['display']) ?>
                </p>

                <?php if (empty($servicePeriods)): ?>
                <p class="text-secondary">No service periods recorded</p>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($servicePeriods as $period): ?>
                        <tr>
                            <td><?= e(date('j M Y', strtotime($period['start_date']))) ?></td>
                            <td><?= $period['end_date'] ? e(date('j M Y', strtotime($period['end_date']))) : '<em>Present</em>' ?></td>
                            <td><?= e($period['notes'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Access Information -->
    <section class="access-info mt-4">
        <h2>Access Information</h2>
        <div class="card">
            <div class="card-body">
                <div class="info-row">
                    <span class="info-label">Access Expires:</span>
                    <span class="info-value">
                        <?php if ($member['access_expires']): ?>
                        <?= e(date('j M Y', strtotime($member['access_expires']))) ?>
                        <?php if (strtotime($member['access_expires']) < strtotime('+30 days')): ?>
                        <span class="badge badge-warning">Expiring soon</span>
                        <?php endif; ?>
                        <?php else: ?>
                        Never
                        <?php endif; ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">PIN Set:</span>
                    <span class="info-value"><?= $member['pin_hash'] ? 'Yes' : 'No' ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Created:</span>
                    <span class="info-value"><?= e(date('j M Y', strtotime($member['created_at'] ?? 'now'))) ?></span>
                </div>
            </div>
        </div>

        <!-- Resend Login Link -->
        <div class="card mt-3">
            <div class="card-body">
                <h3 class="card-title">Send Login Link</h3>
                <p class="text-secondary mb-2">Send a magic login link to this member's email address. Useful if they need to log in on a new device.</p>
                <form method="POST" action="<?= url('/admin/members/' . $member['id'] . '/send-login-link') ?>">
                    <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                    <button type="submit" class="btn btn-secondary">
                        <span class="btn-icon">&#9993;</span> Send Login Link
                    </button>
                </form>
            </div>
        </div>
    </section>

    <div class="admin-nav-back mt-4">
        <a href="<?= url('/admin/members') ?>" class="btn btn-text">&larr; Back to Members</a>
    </div>
</div>

<style>
.member-summary-content {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.member-avatar.large {
    width: 64px;
    height: 64px;
    font-size: 1.5rem;
    border-radius: 50%;
    background: var(--primary, #D32F2F);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
}

.member-details h2 {
    margin: 0 0 0.25rem 0;
    font-size: 1.25rem;
}

.member-details p {
    margin: 0;
}

.small {
    font-size: 0.75rem;
}

.form {
    padding: 1.5rem;
}

.form-group {
    margin-bottom: 1.25rem;
}

.form-label {
    display: block;
    font-weight: 500;
    margin-bottom: 0.5rem;
}

.form-input,
.form-select {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid var(--border, #e0e0e0);
    border-radius: 6px;
    font-size: 1rem;
}

.form-input:disabled {
    background: var(--bg-secondary, #f5f5f5);
    color: var(--text-secondary);
}

.form-input:focus,
.form-select:focus {
    outline: none;
    border-color: var(--primary, #D32F2F);
    box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.1);
}

.has-error .form-input,
.has-error .form-select {
    border-color: var(--error, #f44336);
}

.form-error {
    display: block;
    color: var(--error, #f44336);
    font-size: 0.875rem;
    margin-top: 0.25rem;
}

.form-hint {
    display: block;
    color: var(--text-secondary);
    font-size: 0.75rem;
    margin-top: 0.25rem;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

@media (max-width: 600px) {
    .form-row {
        grid-template-columns: 1fr;
    }
}

.form-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--border, #e0e0e0);
}

.service-total {
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border, #e0e0e0);
}

.info-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--border, #e0e0e0);
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    color: var(--text-secondary);
}

.badge-warning {
    background: var(--warning, #ff9800);
    color: white;
    padding: 0.125rem 0.375rem;
    border-radius: 4px;
    font-size: 0.75rem;
    margin-left: 0.5rem;
}
</style>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../../layouts/main.php';
