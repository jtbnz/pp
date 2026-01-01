<?php
declare(strict_types=1);

/**
 * Member Edit Page Template
 *
 * Form for editing member details.
 * Admin only.
 *
 * Variables:
 * - $member: Member data
 * - $servicePeriods: Array of service periods
 * - $roles: Valid roles
 * - $ranks: Valid ranks
 * - $errors: Form validation errors
 * - $old: Previous form input values
 */

global $config;

$pageTitle = $pageTitle ?? 'Edit ' . $member['name'];
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';
$user = currentUser();

// Start output buffering for content
ob_start();
?>

<div class="page-member-edit">
    <div class="page-header">
        <div class="page-header-content">
            <div class="page-header-back">
                <a href="/members/<?= $member['id'] ?>" class="btn-back" aria-label="Back to member profile">
                    <span class="back-icon">&larr;</span>
                </a>
                <h1>Edit Member</h1>
            </div>
        </div>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="flash-message flash-error">
            <?= e($errors['general']) ?>
            <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <form action="/members/<?= $member['id'] ?>" method="POST" class="edit-form">
        <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="_method" value="PUT">

        <div class="card mb-4">
            <div class="card-header">
                <h2>Personal Information</h2>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input
                        type="email"
                        id="email"
                        class="form-input"
                        value="<?= e($member['email']) ?>"
                        disabled
                    >
                    <small class="form-hint">Email cannot be changed. Create a new member if needed.</small>
                </div>

                <div class="form-group">
                    <label for="name">Name <span class="required">*</span></label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        class="form-input <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                        value="<?= e($old['name'] ?? $member['name']) ?>"
                        required
                    >
                    <?php if (isset($errors['name'])): ?>
                        <span class="form-error"><?= e($errors['name']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="phone">Phone</label>
                    <input
                        type="tel"
                        id="phone"
                        name="phone"
                        class="form-input"
                        value="<?= e($old['phone'] ?? $member['phone'] ?? '') ?>"
                        placeholder="021 123 4567"
                    >
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h2>Role & Rank</h2>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="role">Role <span class="required">*</span></label>
                        <select
                            id="role"
                            name="role"
                            class="form-select <?= isset($errors['role']) ? 'is-invalid' : '' ?>"
                            required
                        >
                            <?php foreach ($roles as $role): ?>
                                <?php $selected = ($old['role'] ?? $member['role']) === $role; ?>
                                <option value="<?= e($role) ?>" <?= $selected ? 'selected' : '' ?>>
                                    <?= e(Member::getRoleDisplayName($role)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['role'])): ?>
                            <span class="form-error"><?= e($errors['role']) ?></span>
                        <?php endif; ?>
                        <small class="form-hint">
                            Firefighter: Basic access |
                            Officer: Can approve leave |
                            Admin: Full brigade management
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status" class="form-select">
                            <option value="active" <?= ($old['status'] ?? $member['status']) === 'active' ? 'selected' : '' ?>>
                                Active
                            </option>
                            <option value="inactive" <?= ($old['status'] ?? $member['status']) === 'inactive' ? 'selected' : '' ?>>
                                Inactive
                            </option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="rank">Rank</label>
                        <select id="rank" name="rank" class="form-select">
                            <option value="">-- No rank --</option>
                            <?php foreach ($ranks as $rank): ?>
                                <?php $selected = ($old['rank'] ?? $member['rank']) === $rank; ?>
                                <option value="<?= e($rank) ?>" <?= $selected ? 'selected' : '' ?>>
                                    <?= e($rank) ?> - <?= e(Member::getRankDisplayName($rank)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="rank_date">Rank Date</label>
                        <input
                            type="date"
                            id="rank_date"
                            name="rank_date"
                            class="form-input"
                            value="<?= e($old['rank_date'] ?? $member['rank_date'] ?? '') ?>"
                        >
                        <small class="form-hint">Date of promotion to current rank</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h2>Access Information</h2>
            </div>
            <div class="card-body">
                <div class="info-row">
                    <span class="info-label">Access Expires</span>
                    <span class="info-value">
                        <?php if ($member['access_expires']): ?>
                            <?= date('j F Y', strtotime($member['access_expires'])) ?>
                        <?php else: ?>
                            <span class="text-secondary">No expiry set</span>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="info-row">
                    <span class="info-label">PIN Set</span>
                    <span class="info-value">
                        <?= $member['pin_hash'] ? 'Yes' : 'No' ?>
                    </span>
                </div>

                <div class="info-row">
                    <span class="info-label">Last Login</span>
                    <span class="info-value">
                        <?php if ($member['last_login_at']): ?>
                            <?= timeAgo($member['last_login_at']) ?>
                        <?php else: ?>
                            <span class="text-secondary">Never</span>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="info-row">
                    <span class="info-label">Member Since</span>
                    <span class="info-value">
                        <?= date('j F Y', strtotime($member['created_at'])) ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="/members/<?= $member['id'] ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<style>
.page-member-edit {
    padding: 1rem;
    max-width: 700px;
    margin: 0 auto;
}

.page-header-content {
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

.card-header {
    padding: 1rem;
    border-bottom: 1px solid var(--border-color, #eee);
}

.card-header h2 {
    margin: 0;
    font-size: 1.125rem;
}

.card-body {
    padding: 1rem;
}

.form-group {
    margin-bottom: 1.25rem;
}

.form-group:last-child {
    margin-bottom: 0;
}

.form-group label {
    display: block;
    font-weight: 500;
    margin-bottom: 0.375rem;
}

.required {
    color: #c62828;
}

.form-input,
.form-select {
    width: 100%;
    padding: 0.625rem 0.75rem;
    border: 1px solid var(--border-color, #ddd);
    border-radius: 6px;
    font-size: 1rem;
    background: white;
}

.form-input:focus,
.form-select:focus {
    outline: none;
    border-color: var(--primary-color, #D32F2F);
    box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.1);
}

.form-input:disabled {
    background: var(--bg-secondary, #f5f5f5);
    color: var(--text-secondary, #666);
    cursor: not-allowed;
}

.form-input.is-invalid,
.form-select.is-invalid {
    border-color: #c62828;
}

.form-error {
    display: block;
    color: #c62828;
    font-size: 0.875rem;
    margin-top: 0.25rem;
}

.form-hint {
    display: block;
    font-size: 0.75rem;
    color: var(--text-secondary, #666);
    margin-top: 0.25rem;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 0;
    border-bottom: 1px solid var(--border-color, #eee);
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    color: var(--text-secondary, #666);
    font-size: 0.875rem;
}

.info-value {
    font-weight: 500;
}

.form-actions {
    display: flex;
    gap: 0.75rem;
    justify-content: flex-end;
    padding-top: 1rem;
}

@media (max-width: 600px) {
    .form-row {
        grid-template-columns: 1fr;
    }

    .form-actions {
        flex-direction: column;
    }

    .form-actions .btn {
        width: 100%;
    }
}
</style>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../layouts/main.php';
