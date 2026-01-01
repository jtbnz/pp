<?php
declare(strict_types=1);

/**
 * Member Card Partial
 *
 * Reusable card component for displaying member info in lists.
 *
 * Variables:
 * - $member: Member data array
 */
?>

<a href="<?= url('/members/' . $member['id']) ?>" class="member-card card">
    <div class="member-card-content">
        <div class="member-avatar">
            <?= strtoupper(substr($member['name'], 0, 1)) ?>
        </div>
        <div class="member-info">
            <h3 class="member-name"><?= e($member['name']) ?></h3>
            <p class="member-email"><?= e($member['email']) ?></p>
            <div class="member-meta">
                <span class="member-role badge badge-<?= $member['role'] ?>">
                    <?= e(Member::getRoleDisplayName($member['role'])) ?>
                </span>
                <?php if (!empty($member['rank'])): ?>
                    <span class="member-rank"><?= e($member['rank']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="member-status">
            <span class="status-dot status-<?= $member['status'] ?>"></span>
        </div>
    </div>
</a>

<style>
.member-card {
    display: block;
    text-decoration: none;
    color: inherit;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.member-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.member-card-content {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
}

.member-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary-color, #D32F2F), var(--primary-dark, #B71C1C));
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    font-weight: bold;
    flex-shrink: 0;
}

.member-info {
    flex: 1;
    min-width: 0;
}

.member-name {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.member-email {
    margin: 0.125rem 0 0;
    font-size: 0.875rem;
    color: var(--text-secondary, #666);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.member-meta {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.5rem;
    flex-wrap: wrap;
}

.member-role.badge {
    font-size: 0.625rem;
    padding: 0.125rem 0.5rem;
}

.badge-firefighter { background: #e3f2fd; color: #1565c0; }
.badge-officer { background: #fff3e0; color: #ef6c00; }
.badge-admin { background: #f3e5f5; color: #7b1fa2; }
.badge-superadmin { background: #ffebee; color: #c62828; }

.member-rank {
    font-size: 0.75rem;
    color: var(--text-secondary, #666);
    font-weight: 500;
}

.member-status {
    flex-shrink: 0;
}

.status-dot {
    display: block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
}

.status-dot.status-active {
    background: #4caf50;
}

.status-dot.status-inactive {
    background: #9e9e9e;
}
</style>
