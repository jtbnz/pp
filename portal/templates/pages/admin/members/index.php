<?php
declare(strict_types=1);

/**
 * Admin Members List Template
 *
 * Member list with filters, search, and management actions.
 */

global $config;

$pageTitle = $pageTitle ?? 'Manage Members';
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

<div class="page-admin-members">
    <header class="page-header">
        <div class="header-row">
            <div>
                <h1>Members</h1>
                <p class="text-secondary"><?= $totalCount ?> member<?= $totalCount !== 1 ? 's' : '' ?></p>
            </div>
            <a href="/admin/members/invite" class="btn btn-primary">
                <span class="btn-icon">&#43;</span> Invite
            </a>
        </div>
    </header>

    <?php if ($flashMessage): ?>
    <div class="flash-message flash-<?= e($flashType) ?>">
        <?= e($flashMessage) ?>
        <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <section class="filters-section mb-3">
        <form method="GET" action="/admin/members" class="filters-form">
            <div class="filter-group">
                <input type="text" name="search" placeholder="Search members..."
                       value="<?= e($filters['search'] ?? '') ?>" class="form-input">
            </div>
            <div class="filter-group">
                <select name="role" class="form-select">
                    <option value="">All Roles</option>
                    <?php foreach ($roles as $role): ?>
                    <option value="<?= e($role) ?>" <?= ($filters['role'] ?? '') === $role ? 'selected' : '' ?>>
                        <?= e(Member::getRoleDisplayName($role)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <select name="status" class="form-select">
                    <option value="active" <?= ($filters['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="" <?= !isset($filters['status']) || $filters['status'] === '' ? 'selected' : '' ?>>All</option>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">Filter</button>
            <?php if (!empty($filters['search']) || !empty($filters['role']) || (isset($filters['status']) && $filters['status'] !== 'active')): ?>
            <a href="/admin/members" class="btn btn-text">Clear</a>
            <?php endif; ?>
        </form>
    </section>

    <!-- Members List -->
    <section class="members-list">
        <?php if (empty($members)): ?>
        <div class="card">
            <div class="card-body text-center p-4">
                <p class="text-secondary">No members found</p>
                <a href="/admin/members/invite" class="btn btn-primary mt-2">Invite Member</a>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th class="hide-mobile">Email</th>
                            <th>Role</th>
                            <th class="hide-mobile">Rank</th>
                            <th class="hide-mobile">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $member): ?>
                        <tr class="<?= $member['status'] === 'inactive' ? 'row-inactive' : '' ?>">
                            <td>
                                <div class="member-info">
                                    <span class="member-avatar"><?= strtoupper(substr($member['name'], 0, 1)) ?></span>
                                    <div>
                                        <div class="member-name"><?= e($member['name']) ?></div>
                                        <div class="member-email show-mobile text-secondary"><?= e($member['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="hide-mobile"><?= e($member['email']) ?></td>
                            <td>
                                <span class="badge badge-<?= $member['role'] === 'admin' || $member['role'] === 'superadmin' ? 'primary' : ($member['role'] === 'officer' ? 'info' : 'default') ?>">
                                    <?= e(Member::getRoleDisplayName($member['role'])) ?>
                                </span>
                            </td>
                            <td class="hide-mobile">
                                <?php if ($member['rank']): ?>
                                <span class="rank-badge"><?= e($member['rank']) ?></span>
                                <?php else: ?>
                                <span class="text-secondary">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="hide-mobile">
                                <span class="status-indicator status-<?= $member['status'] ?>">
                                    <?= ucfirst(e($member['status'])) ?>
                                </span>
                            </td>
                            <td class="actions">
                                <a href="/admin/members/<?= $member['id'] ?>" class="btn btn-sm btn-secondary">Edit</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <div class="admin-nav-back mt-4">
        <a href="/admin" class="btn btn-text">&larr; Back to Dashboard</a>
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
    gap: 0.5rem;
    align-items: center;
}

.filter-group {
    flex: 1;
    min-width: 150px;
}

@media (max-width: 768px) {
    .filter-group {
        min-width: 100%;
    }
}

.member-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.member-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--primary, #D32F2F);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.875rem;
}

.member-name {
    font-weight: 500;
}

.member-email {
    font-size: 0.75rem;
}

.row-inactive {
    opacity: 0.6;
}

.badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 4px;
    background: var(--bg-secondary, #e0e0e0);
}

.badge-primary {
    background: var(--primary, #D32F2F);
    color: white;
}

.badge-info {
    background: var(--info, #2196F3);
    color: white;
}

.rank-badge {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-secondary);
}

.status-indicator {
    font-size: 0.75rem;
    padding: 0.125rem 0.5rem;
    border-radius: 10px;
}

.status-active {
    background: var(--success-bg, #e8f5e9);
    color: var(--success, #4caf50);
}

.status-inactive {
    background: var(--error-bg, #ffebee);
    color: var(--error, #f44336);
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table th,
.table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid var(--border, #e0e0e0);
}

.table th {
    font-weight: 600;
    font-size: 0.75rem;
    text-transform: uppercase;
    color: var(--text-secondary);
}

.actions {
    text-align: right;
}

.hide-mobile {
    display: none;
}

.show-mobile {
    display: block;
}

@media (min-width: 768px) {
    .hide-mobile {
        display: table-cell;
    }
    .show-mobile {
        display: none;
    }
}

.table-responsive {
    overflow-x: auto;
}
</style>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../../layouts/main.php';
