<?php
declare(strict_types=1);

/**
 * Admin Edit Poll
 *
 * Form to edit an existing poll.
 *
 * Variables:
 * - $poll: array - The poll to edit
 */

global $config;

$pageTitle = $pageTitle ?? 'Edit Poll';
$appName = $config['app_name'] ?? 'Puke Portal';
$user = currentUser();

// Get form errors and data from session
$formErrors = $_SESSION['form_errors'] ?? [];
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['form_data']);

// Get flash message if any
$flashMessage = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// Use form data if available (on validation error), otherwise use poll data
$title = $formData['title'] ?? $poll['title'] ?? '';
$description = $formData['description'] ?? $poll['description'] ?? '';
$type = $formData['type'] ?? $poll['type'] ?? 'single';
$closesAt = $formData['closes_at'] ?? ($poll['closes_at'] ? fromUtc($poll['closes_at'], 'Y-m-d\TH:i') : '');
$options = $formData['options'] ?? array_column($poll['options'] ?? [], 'text');

$hasVotes = ($poll['total_votes'] ?? 0) > 0;

// Start output buffering for content
ob_start();
?>

<div class="page-admin-edit-poll">
    <header class="page-header">
        <h1><?= e($pageTitle) ?></h1>
    </header>

    <?php if ($flashMessage): ?>
        <div class="flash-message flash-<?= e($flashType) ?>">
            <?= e($flashMessage) ?>
            <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
        </div>
    <?php endif; ?>

    <?php if ($hasVotes): ?>
        <div class="alert alert-warning mb-4">
            <strong>Note:</strong> This poll has <?= $poll['total_votes'] ?> vote(s). Editing options will clear all existing votes.
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" action="<?= url('/admin/polls/' . $poll['id']) ?>" class="form" id="poll-form">
            <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="_method" value="PUT">

            <div class="form-group <?= isset($formErrors['title']) ? 'has-error' : '' ?>">
                <label for="title" class="form-label">Question / Title *</label>
                <input type="text" id="title" name="title" class="form-input"
                       value="<?= e($title) ?>"
                       placeholder="What do you want to ask?"
                       required>
                <?php if (isset($formErrors['title'])): ?>
                    <span class="form-error"><?= e($formErrors['title']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="description" class="form-label">Description</label>
                <textarea id="description" name="description" class="form-textarea" rows="3"
                          placeholder="Add more context if needed"><?= e($description) ?></textarea>
            </div>

            <div class="form-group <?= isset($formErrors['type']) ? 'has-error' : '' ?>">
                <label class="form-label">Poll Type *</label>
                <div class="radio-group">
                    <label class="radio-option">
                        <input type="radio" name="type" value="single" <?= $type === 'single' ? 'checked' : '' ?>>
                        <span>Single choice</span>
                        <small>Voters can select only one option</small>
                    </label>
                    <label class="radio-option">
                        <input type="radio" name="type" value="multi" <?= $type === 'multi' ? 'checked' : '' ?>>
                        <span>Multiple choice</span>
                        <small>Voters can select multiple options</small>
                    </label>
                </div>
                <?php if (isset($formErrors['type'])): ?>
                    <span class="form-error"><?= e($formErrors['type']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group <?= isset($formErrors['options']) ? 'has-error' : '' ?>">
                <label class="form-label">Options *</label>
                <?php if ($hasVotes): ?>
                    <div class="form-hint mb-2" style="color: var(--warning, #ff9800);">
                        Changing options will clear all existing votes
                    </div>
                <?php endif; ?>
                <div class="options-container" id="options-container">
                    <?php
                    if (count($options) < 2) {
                        $options = array_pad($options, 2, '');
                    }
                    foreach ($options as $index => $optionText):
                    ?>
                        <div class="option-row">
                            <input type="text" name="options[]" class="form-input"
                                   placeholder="Option <?= $index + 1 ?>"
                                   value="<?= e($optionText) ?>">
                            <button type="button" class="btn btn-icon remove-option" aria-label="Remove">&times;</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-outline add-option-btn" id="add-option-btn">
                    + Add Option
                </button>
                <?php if (isset($formErrors['options'])): ?>
                    <span class="form-error"><?= e($formErrors['options']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="closes_at" class="form-label">Close Date (Optional)</label>
                <input type="datetime-local" id="closes_at" name="closes_at" class="form-input"
                       value="<?= e($closesAt) ?>">
                <span class="form-hint">Leave empty for no expiry</span>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="<?= url('/admin/polls') ?>" class="btn btn-secondary">Cancel</a>
                <?php if ($poll['status'] === 'active'): ?>
                    <button type="button" class="btn btn-outline" onclick="closePoll()">Close Poll</button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h3>Poll Statistics</h3>
        </div>
        <div class="card-body">
            <dl class="stats-grid">
                <div class="stat-item">
                    <dt>Status</dt>
                    <dd>
                        <?php if ($poll['status'] === 'active'): ?>
                            <span class="badge badge-success">Active</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">Closed</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="stat-item">
                    <dt>Total Voters</dt>
                    <dd><?= $poll['total_votes'] ?? 0 ?></dd>
                </div>
                <div class="stat-item">
                    <dt>Created</dt>
                    <dd><?= fromUtc($poll['created_at'], 'j M Y, g:ia') ?></dd>
                </div>
                <div class="stat-item">
                    <dt>Created By</dt>
                    <dd><?= e($poll['created_by_name'] ?? 'Unknown') ?></dd>
                </div>
            </dl>
        </div>
    </div>

    <div class="admin-nav-back mt-4">
        <a href="<?= url('/admin/polls') ?>" class="btn btn-text">&larr; Back to Polls</a>
    </div>
</div>

<style>
.form {
    padding: 1.5rem;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-label {
    display: block;
    font-weight: 500;
    margin-bottom: 0.5rem;
}

.form-input,
.form-textarea {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 1rem;
}

.form-input:focus,
.form-textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.1);
}

.has-error .form-input,
.has-error .form-textarea {
    border-color: var(--error);
}

.form-error {
    display: block;
    color: var(--error);
    font-size: 0.875rem;
    margin-top: 0.25rem;
}

.form-hint {
    display: block;
    color: var(--text-secondary);
    font-size: 0.75rem;
    margin-top: 0.25rem;
}

.radio-group {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.radio-option {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1rem;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}

.radio-option:hover {
    border-color: var(--primary);
}

.radio-option:has(input:checked) {
    border-color: var(--primary);
    background: rgba(211, 47, 47, 0.05);
}

.radio-option input {
    margin-top: 0.25rem;
}

.radio-option span {
    display: block;
    font-weight: 500;
}

.radio-option small {
    display: block;
    color: var(--text-secondary);
    font-size: 0.8125rem;
}

.options-container {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}

.option-row {
    display: flex;
    gap: 0.5rem;
}

.option-row .form-input {
    flex: 1;
}

.btn-icon {
    padding: 0.75rem;
    background: transparent;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    cursor: pointer;
    font-size: 1.25rem;
    line-height: 1;
    color: var(--text-secondary);
}

.btn-icon:hover {
    background: var(--error);
    border-color: var(--error);
    color: white;
}

.add-option-btn {
    width: auto;
}

.form-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--border-color);
}

.alert {
    padding: 1rem;
    border-radius: 6px;
    background: rgba(255, 152, 0, 0.1);
    border: 1px solid var(--warning, #ff9800);
    color: var(--text-primary);
}

.alert-warning {
    background: rgba(255, 152, 0, 0.1);
    border-color: var(--warning, #ff9800);
}

.card-header {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
}

.card-header h3 {
    margin: 0;
    font-size: 1.125rem;
}

.card-body {
    padding: 1.5rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1.5rem;
    margin: 0;
}

.stat-item dt {
    font-size: 0.875rem;
    color: var(--text-secondary);
    margin-bottom: 0.25rem;
}

.stat-item dd {
    font-size: 1rem;
    font-weight: 500;
    margin: 0;
}

.badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 4px;
}

.badge-success {
    background: var(--success, #4caf50);
    color: white;
}

.badge-secondary {
    background: var(--text-secondary);
    color: white;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('options-container');
    const addBtn = document.getElementById('add-option-btn');
    let optionCount = container.querySelectorAll('.option-row').length;

    // Add new option
    addBtn.addEventListener('click', function() {
        optionCount++;
        const row = document.createElement('div');
        row.className = 'option-row';
        row.innerHTML = `
            <input type="text" name="options[]" class="form-input" placeholder="Option ${optionCount}">
            <button type="button" class="btn btn-icon remove-option" aria-label="Remove">&times;</button>
        `;
        container.appendChild(row);
        row.querySelector('input').focus();
    });

    // Remove option
    container.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-option')) {
            const rows = container.querySelectorAll('.option-row');
            if (rows.length > 2) {
                e.target.closest('.option-row').remove();
            } else {
                alert('A poll must have at least 2 options.');
            }
        }
    });
});

function closePoll() {
    if (confirm('Are you sure you want to close this poll? Voting will no longer be possible.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '<?= url('/admin/polls/' . $poll['id'] . '/close') ?>';

        const csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = '_csrf_token';
        csrf.value = '<?= csrfToken() ?>';
        form.appendChild(csrf);

        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../../layouts/main.php';
?>
