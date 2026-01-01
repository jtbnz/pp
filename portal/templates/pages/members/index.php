<?php
declare(strict_types=1);

/**
 * Members List Page Template
 *
 * Displays list of brigade members with filtering options.
 * Admin only.
 *
 * Variables:
 * - $members: Array of member data
 * - $filters: Current filter values
 * - $pagination: Pagination info
 * - $roles: Valid roles
 * - $ranks: Valid ranks
 */

global $config;

$pageTitle = $pageTitle ?? 'Members';
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

<div class="page-members">
    <div class="page-header">
        <div class="page-header-content">
            <h1>Members</h1>
            <a href="/admin/members/invite" class="btn btn-primary">
                <span class="btn-icon">+</span>
                Invite Member
            </a>
        </div>
    </div>

    <?php if ($flashMessage): ?>
        <div class="flash-message flash-<?= e($flashType) ?>">
            <?= e($flashMessage) ?>
            <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="filters-section card mb-4">
        <form action="/members" method="GET" class="filters-form">
            <div class="filter-group">
                <label for="search" class="filter-label">Search</label>
                <input
                    type="text"
                    id="search"
                    name="search"
                    class="form-input"
                    placeholder="Name or email..."
                    value="<?= e($filters['search'] ?? '') ?>"
                >
            </div>

            <div class="filter-group">
                <label for="role" class="filter-label">Role</label>
                <select id="role" name="role" class="form-select">
                    <option value="">All Roles</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= e($role) ?>" <?= ($filters['role'] ?? '') === $role ? 'selected' : '' ?>>
                            <?= e(Member::getRoleDisplayName($role)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="status" class="filter-label">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="active" <?= ($filters['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="" <?= ($filters['status'] ?? '') === '' ? 'selected' : '' ?>>All</option>
                </select>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-secondary">Filter</button>
                <a href="/members" class="btn btn-text">Clear</a>
            </div>
        </form>
    </div>

    <!-- Members List -->
    <?php if (empty($members)): ?>
        <div class="card">
            <div class="card-body text-center p-4">
                <p class="text-secondary">No members found matching your criteria.</p>
                <?php if (!empty($filters['search']) || !empty($filters['role']) || ($filters['status'] ?? 'active') !== 'active'): ?>
                    <a href="/members" class="btn btn-text mt-2">Clear filters</a>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="members-list">
            <?php foreach ($members as $member): ?>
                <?php include __DIR__ . '/../../partials/member-card.php'; ?>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($pagination['total'] > 1): ?>
            <div class="pagination">
                <?php if ($pagination['current'] > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $pagination['current'] - 1])) ?>" class="pagination-link pagination-prev">
                        Previous
                    </a>
                <?php endif; ?>

                <span class="pagination-info">
                    Page <?= $pagination['current'] ?> of <?= $pagination['total'] ?>
                    (<?= $pagination['count'] ?> members)
                </span>

                <?php if ($pagination['current'] < $pagination['total']): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $pagination['current'] + 1])) ?>" class="pagination-link pagination-next">
                        Next
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
.page-members {
    padding: 1rem;
    max-width: 1200px;
    margin: 0 auto;
}

.page-header {
    margin-bottom: 1.5rem;
}

.page-header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.page-header h1 {
    margin: 0;
    font-size: 1.75rem;
}

.filters-section {
    padding: 1rem;
}

.filters-form {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: flex-end;
}

.filter-group {
    flex: 1;
    min-width: 150px;
}

.filter-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    margin-bottom: 0.25rem;
    color: var(--text-secondary, #666);
}

.filter-actions {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

.members-list {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1rem;
    margin-top: 1.5rem;
    padding: 1rem;
}

.pagination-link {
    padding: 0.5rem 1rem;
    border: 1px solid var(--border-color, #ddd);
    border-radius: 4px;
    text-decoration: none;
    color: var(--text-color, #333);
}

.pagination-link:hover {
    background: var(--bg-hover, #f5f5f5);
}

.pagination-info {
    color: var(--text-secondary, #666);
    font-size: 0.875rem;
}

@media (max-width: 600px) {
    .filters-form {
        flex-direction: column;
    }

    .filter-group {
        width: 100%;
    }

    .filter-actions {
        width: 100%;
        justify-content: flex-end;
    }

    .members-list {
        grid-template-columns: 1fr;
    }

    .pagination {
        flex-direction: column;
    }
}
</style>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../layouts/main.php';
