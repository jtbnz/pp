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
            <div class="header-actions">
                <button type="button" id="import-dlb-btn" class="btn btn-secondary" title="Import members from DLB">
                    <span class="btn-icon">&#128229;</span> Import from DLB
                </button>
                <a href="<?= url('/admin/members/invite') ?>" class="btn btn-primary">
                    <span class="btn-icon">&#43;</span> Invite
                </a>
            </div>
        </div>
    </header>

    <?php if ($flashMessage): ?>
    <div class="flash-message flash-<?= e($flashType) ?>">
        <?= e($flashMessage) ?>
        <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Import Results Modal -->
    <div id="import-modal" class="modal" hidden>
        <div class="modal-backdrop"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h3>Import from DLB</h3>
                <button type="button" class="modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div id="import-loading" class="text-center p-3">
                    <div class="spinner"></div>
                    <p>Importing members from DLB...</p>
                </div>
                <div id="import-results" hidden></div>
            </div>
            <div class="modal-footer" hidden>
                <button type="button" class="btn btn-primary" id="import-close-btn">Close</button>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <section class="filters-section mb-3">
        <form method="GET" action="<?= url('/admin/members') ?>" class="filters-form">
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
            <a href="<?= url('/admin/members') ?>" class="btn btn-text">Clear</a>
            <?php endif; ?>
        </form>
    </section>

    <!-- Members List -->
    <section class="members-list">
        <?php if (empty($members)): ?>
        <div class="card">
            <div class="card-body text-center p-4">
                <p class="text-secondary">No members found</p>
                <a href="<?= url('/admin/members/invite') ?>" class="btn btn-primary mt-2">Invite Member</a>
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
                                <a href="<?= url('/admin/members/' . $member['id']) ?>" class="btn btn-sm btn-secondary">Edit</a>
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

.header-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

/* Modal styles */
.modal {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal[hidden] {
    display: none;
}

.modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.5);
}

.modal-content {
    position: relative;
    background: var(--bg-primary, white);
    border-radius: 8px;
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    border-bottom: 1px solid var(--border, #e0e0e0);
}

.modal-header h3 {
    margin: 0;
    font-size: 1.125rem;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    opacity: 0.5;
    line-height: 1;
}

.modal-close:hover {
    opacity: 1;
}

.modal-body {
    padding: 1rem;
    overflow-y: auto;
}

.modal-footer {
    padding: 1rem;
    border-top: 1px solid var(--border, #e0e0e0);
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
}

.modal-footer[hidden] {
    display: none;
}

.spinner {
    width: 40px;
    height: 40px;
    border: 3px solid var(--border, #e0e0e0);
    border-top-color: var(--primary, #D32F2F);
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin: 0 auto 1rem;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.import-summary {
    margin-bottom: 1rem;
}

.import-summary .stat {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--border, #e0e0e0);
}

.import-summary .stat:last-child {
    border-bottom: none;
}

.import-summary .stat-label {
    color: var(--text-secondary);
}

.import-summary .stat-value {
    font-weight: 600;
}

.import-summary .stat-value.success {
    color: var(--success, #4caf50);
}

.import-summary .stat-value.info {
    color: var(--info, #2196F3);
}

.import-details {
    margin-top: 1rem;
}

.import-details summary {
    cursor: pointer;
    padding: 0.5rem 0;
    color: var(--primary, #D32F2F);
    font-weight: 500;
}

.import-details ul {
    margin: 0.5rem 0;
    padding-left: 1.5rem;
}

.import-details li {
    padding: 0.25rem 0;
    font-size: 0.875rem;
}

.import-error {
    background: var(--error-bg, #ffebee);
    color: var(--error, #f44336);
    padding: 1rem;
    border-radius: 4px;
}
</style>

<script>
(function() {
    const importBtn = document.getElementById('import-dlb-btn');
    const modal = document.getElementById('import-modal');
    const modalClose = modal.querySelector('.modal-close');
    const modalBackdrop = modal.querySelector('.modal-backdrop');
    const modalFooter = modal.querySelector('.modal-footer');
    const closeBtn = document.getElementById('import-close-btn');
    const loadingEl = document.getElementById('import-loading');
    const resultsEl = document.getElementById('import-results');

    function showModal() {
        modal.hidden = false;
        loadingEl.hidden = false;
        resultsEl.hidden = true;
        modalFooter.hidden = true;
    }

    function hideModal() {
        modal.hidden = true;
    }

    function showResults(data) {
        loadingEl.hidden = true;
        resultsEl.hidden = false;
        modalFooter.hidden = false;

        if (!data.success && data.error) {
            resultsEl.innerHTML = `<div class="import-error">${escapeHtml(data.error)}</div>`;
            return;
        }

        const r = data.results || {};
        let html = `
            <div class="import-summary">
                <div class="stat">
                    <span class="stat-label">Total in DLB</span>
                    <span class="stat-value">${r.total_dlb || 0}</span>
                </div>
                <div class="stat">
                    <span class="stat-label">New members imported</span>
                    <span class="stat-value success">${r.imported || 0}</span>
                </div>
                <div class="stat">
                    <span class="stat-label">Existing members linked</span>
                    <span class="stat-value info">${r.linked || 0}</span>
                </div>
                <div class="stat">
                    <span class="stat-label">Skipped</span>
                    <span class="stat-value">${r.skipped || 0}</span>
                </div>
            </div>
        `;

        // Show details if any members were imported or linked
        if ((r.imported_members && r.imported_members.length) || (r.linked_members && r.linked_members.length)) {
            html += '<div class="import-details">';

            if (r.imported_members && r.imported_members.length) {
                html += `<details><summary>Imported Members (${r.imported_members.length})</summary><ul>`;
                r.imported_members.forEach(m => {
                    html += `<li>${escapeHtml(m.name)}</li>`;
                });
                html += '</ul></details>';
            }

            if (r.linked_members && r.linked_members.length) {
                html += `<details><summary>Linked Members (${r.linked_members.length})</summary><ul>`;
                r.linked_members.forEach(m => {
                    html += `<li>${escapeHtml(m.name)}</li>`;
                });
                html += '</ul></details>';
            }

            html += '</div>';
        }

        if (r.errors && r.errors.length) {
            html += '<div class="import-error" style="margin-top:1rem"><strong>Errors:</strong><ul>';
            r.errors.forEach(err => {
                html += `<li>${escapeHtml(err)}</li>`;
            });
            html += '</ul></div>';
        }

        resultsEl.innerHTML = html;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    async function doImport() {
        showModal();

        try {
            const basePath = window.BASE_PATH || '';
            const response = await fetch(basePath + '/api/sync/import-members', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            const data = await response.json();
            showResults(data);

            // Reload page if members were imported
            if (data.results && (data.results.imported > 0 || data.results.linked > 0)) {
                closeBtn.textContent = 'Close & Refresh';
                closeBtn.onclick = function() {
                    window.location.reload();
                };
            }

        } catch (err) {
            showResults({ success: false, error: 'Failed to connect to server: ' + err.message });
        }
    }

    importBtn.addEventListener('click', doImport);
    modalClose.addEventListener('click', hideModal);
    modalBackdrop.addEventListener('click', hideModal);
    closeBtn.addEventListener('click', hideModal);
})();
</script>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../../layouts/main.php';
