<?php
declare(strict_types=1);

/**
 * Admin Dashboard Template
 *
 * Main admin dashboard with stats grid, quick actions, and recent activity.
 */

global $config;

$pageTitle = $pageTitle ?? 'Admin Dashboard';
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';
$user = currentUser();

// Start output buffering for content
ob_start();
?>

<div class="page-admin-dashboard">
    <header class="page-header">
        <h1>Admin Dashboard</h1>
        <p class="text-secondary">Manage your brigade</p>
    </header>

    <!-- Stats Grid -->
    <section class="stats-grid">
        <?php require __DIR__ . '/../../partials/admin/stat-card.php'; ?>
        <?= renderStatCard('Active Members', $stats['active_members'], '&#128100;', '/admin/members', 'primary') ?>
        <?= renderStatCard('Pending Leave', $stats['pending_leave'], '&#128198;', '/admin/leave?status=pending', $stats['pending_leave'] > 0 ? 'warning' : 'default') ?>
        <?= renderStatCard('Upcoming Events', $stats['upcoming_events'], '&#128197;', '/admin/events', 'info') ?>
        <?= renderStatCard('Active Notices', $stats['active_notices'], '&#128240;', '/admin/notices', 'default') ?>
    </section>

    <!-- Quick Actions -->
    <section class="quick-actions mb-4">
        <h2>Quick Actions</h2>
        <div class="action-buttons">
            <a href="<?= url('/admin/members/invite') ?>" class="btn btn-primary">
                <span class="btn-icon">&#43;</span> Invite Member
            </a>
            <a href="<?= url('/admin/events/create') ?>" class="btn btn-secondary">
                <span class="btn-icon">&#128197;</span> Create Event
            </a>
            <a href="<?= url('/admin/notices/create') ?>" class="btn btn-secondary">
                <span class="btn-icon">&#128240;</span> Create Notice
            </a>
            <a href="<?= url('/admin/polls/create') ?>" class="btn btn-secondary">
                <span class="btn-icon">&#128202;</span> Create Poll
            </a>
            <?php if ($pendingLeaveCount > 0): ?>
            <a href="<?= url('/admin/leave?status=pending') ?>" class="btn btn-warning">
                <span class="btn-icon">&#9989;</span> Review Leave (<?= $pendingLeaveCount ?>)
            </a>
            <?php endif; ?>
        </div>
    </section>

    <!-- Recent Activity -->
    <section class="recent-activity">
        <div class="section-header">
            <h2>Recent Activity</h2>
        </div>
        <div class="card">
            <div class="activity-list">
                <?php if (empty($recentActivity)): ?>
                    <p class="text-secondary text-center p-3">No recent activity</p>
                <?php else: ?>
                    <?php require __DIR__ . '/../../partials/admin/activity-item.php'; ?>
                    <?php foreach ($recentActivity as $activity): ?>
                        <?= renderActivityItem($activity) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Admin Navigation -->
    <section class="admin-nav mt-4">
        <h2>Management</h2>
        <div class="admin-nav-grid">
            <a href="<?= url('/admin/members') ?>" class="admin-nav-item">
                <span class="nav-icon">&#128100;</span>
                <span class="nav-label">Members</span>
                <span class="nav-count"><?= $stats['active_members'] ?></span>
            </a>
            <a href="<?= url('/admin/events') ?>" class="admin-nav-item">
                <span class="nav-icon">&#128197;</span>
                <span class="nav-label">Events</span>
                <span class="nav-count"><?= $stats['upcoming_events'] ?></span>
            </a>
            <a href="<?= url('/admin/notices') ?>" class="admin-nav-item">
                <span class="nav-icon">&#128240;</span>
                <span class="nav-label">Notices</span>
                <span class="nav-count"><?= $stats['active_notices'] ?></span>
            </a>
            <a href="<?= url('/admin/leave') ?>" class="admin-nav-item">
                <span class="nav-icon">&#128198;</span>
                <span class="nav-label">Leave</span>
                <?php if ($pendingLeaveCount > 0): ?>
                <span class="nav-count badge-warning"><?= $pendingLeaveCount ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= url('/admin/polls') ?>" class="admin-nav-item">
                <span class="nav-icon">&#128202;</span>
                <span class="nav-label">Polls</span>
                <?php if (isset($stats['active_polls']) && $stats['active_polls'] > 0): ?>
                <span class="nav-count"><?= $stats['active_polls'] ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= url('/admin/settings') ?>" class="admin-nav-item">
                <span class="nav-icon">&#9881;</span>
                <span class="nav-label">Settings</span>
            </a>
        </div>
    </section>
</div>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

@media (min-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

.quick-actions .action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.quick-actions .btn {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.activity-list {
    max-height: 400px;
    overflow-y: auto;
}

.admin-nav-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

@media (min-width: 768px) {
    .admin-nav-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

.admin-nav-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 1.5rem 1rem;
    background: var(--bg-secondary, #f5f5f5);
    border-radius: 8px;
    text-decoration: none;
    color: inherit;
    transition: background-color 0.2s, transform 0.1s;
}

.admin-nav-item:hover {
    background: var(--bg-hover, #e8e8e8);
    transform: translateY(-2px);
}

.admin-nav-item .nav-icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
}

.admin-nav-item .nav-label {
    font-weight: 500;
}

.admin-nav-item .nav-count {
    font-size: 0.875rem;
    color: var(--text-secondary);
    margin-top: 0.25rem;
}

.admin-nav-item .nav-count.badge-warning {
    background: var(--warning, #ff9800);
    color: white;
    padding: 0.125rem 0.5rem;
    border-radius: 10px;
}
</style>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../layouts/main.php';
