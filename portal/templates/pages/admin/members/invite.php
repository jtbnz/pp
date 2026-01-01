<?php
declare(strict_types=1);

/**
 * Admin Invite Member Template
 *
 * Form to invite a new member to the brigade.
 */

global $config;

$pageTitle = $pageTitle ?? 'Invite Member';
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

// Start output buffering for content
ob_start();
?>

<div class="page-admin-invite">
    <header class="page-header">
        <h1>Invite Member</h1>
        <p class="text-secondary">Send an invitation to join the brigade</p>
    </header>

    <?php if ($flashMessage): ?>
    <div class="flash-message flash-<?= e($flashType) ?>">
        <?= e($flashMessage) ?>
        <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
    </div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" action="<?= url('/admin/members/invite') ?>" class="form">
            <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">

            <div class="form-group <?= isset($formErrors['email']) ? 'has-error' : '' ?>">
                <label for="email" class="form-label">Email Address *</label>
                <input type="email" id="email" name="email" class="form-input"
                       value="<?= e($formData['email'] ?? '') ?>"
                       placeholder="member@example.com" required>
                <?php if (isset($formErrors['email'])): ?>
                <span class="form-error"><?= e($formErrors['email']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group <?= isset($formErrors['name']) ? 'has-error' : '' ?>">
                <label for="name" class="form-label">Full Name *</label>
                <input type="text" id="name" name="name" class="form-input"
                       value="<?= e($formData['name'] ?? '') ?>"
                       placeholder="John Smith" required>
                <?php if (isset($formErrors['name'])): ?>
                <span class="form-error"><?= e($formErrors['name']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="phone" class="form-label">Phone Number</label>
                <input type="tel" id="phone" name="phone" class="form-input"
                       value="<?= e($formData['phone'] ?? '') ?>"
                       placeholder="021 123 4567">
            </div>

            <div class="form-row">
                <div class="form-group <?= isset($formErrors['role']) ? 'has-error' : '' ?>">
                    <label for="role" class="form-label">Role *</label>
                    <select id="role" name="role" class="form-select" required>
                        <?php foreach ($roles as $role): ?>
                        <?php if ($role !== 'superadmin' || hasRole('superadmin')): ?>
                        <option value="<?= e($role) ?>" <?= ($formData['role'] ?? 'firefighter') === $role ? 'selected' : '' ?>>
                            <?= e(Member::getRoleDisplayName($role)) ?>
                        </option>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($formErrors['role'])): ?>
                    <span class="form-error"><?= e($formErrors['role']) ?></span>
                    <?php endif; ?>
                    <span class="form-hint">
                        Firefighter: Basic access. Officer: Can approve leave. Admin: Full management.
                    </span>
                </div>

                <div class="form-group <?= isset($formErrors['rank']) ? 'has-error' : '' ?>">
                    <label for="rank" class="form-label">Rank</label>
                    <select id="rank" name="rank" class="form-select">
                        <option value="">No rank</option>
                        <?php foreach ($ranks as $rank): ?>
                        <option value="<?= e($rank) ?>" <?= ($formData['rank'] ?? '') === $rank ? 'selected' : '' ?>>
                            <?= e($rank) ?> - <?= e(Member::getRankDisplayName($rank)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($formErrors['rank'])): ?>
                    <span class="form-error"><?= e($formErrors['rank']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Send Invitation</button>
                <a href="<?= url('/admin/members') ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <div class="info-box mt-4">
        <h3>About Invitations</h3>
        <ul>
            <li>The member will receive an email with a magic link to activate their account.</li>
            <li>Access is valid for 5 years from activation.</li>
            <li>They can optionally set a 6-digit PIN for quick re-authentication.</li>
        </ul>
    </div>
</div>

<style>
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

.info-box {
    background: var(--bg-secondary, #f5f5f5);
    padding: 1rem 1.5rem;
    border-radius: 8px;
}

.info-box h3 {
    font-size: 0.875rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.info-box ul {
    margin: 0;
    padding-left: 1.25rem;
    font-size: 0.875rem;
    color: var(--text-secondary);
}

.info-box li {
    margin-bottom: 0.25rem;
}
</style>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../../layouts/main.php';
