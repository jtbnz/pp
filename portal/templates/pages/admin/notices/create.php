<?php
declare(strict_types=1);

/**
 * Admin Create Notice Template
 *
 * Form to create a new notice (admin only).
 */

global $config;

$pageTitle = $pageTitle ?? 'Create Notice';
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';
$user = currentUser();

// Get form errors and data from session
$errors = $_SESSION['form_errors'] ?? [];
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['form_data']);

// Start output buffering for content
ob_start();
?>

<div class="page-admin-notice-form">
    <nav class="breadcrumb-nav mb-3">
        <a href="<?= url('/admin/notices') ?>" class="breadcrumb-link">&larr; Back to Notices</a>
    </nav>

    <header class="page-header mb-4">
        <h1><?= e($pageTitle) ?></h1>
    </header>

    <div class="card">
        <div class="card-body">
            <form action="<?= url('/admin/notices') ?>" method="POST" class="notice-form">
                <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">

                <div class="form-group">
                    <label for="title" class="form-label">Title <span class="required">*</span></label>
                    <input
                        type="text"
                        id="title"
                        name="title"
                        class="form-input <?= !empty($errors['title']) ? 'error' : '' ?>"
                        value="<?= e($formData['title'] ?? '') ?>"
                        required
                        maxlength="200"
                        placeholder="Enter notice title"
                    >
                    <?php if (!empty($errors['title'])): ?>
                        <span class="form-error"><?= e($errors['title']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="type" class="form-label">Type <span class="required">*</span></label>
                    <select id="type" name="type" class="form-select <?= !empty($errors['type']) ? 'error' : '' ?>">
                        <option value="standard" <?= ($formData['type'] ?? 'standard') === 'standard' ? 'selected' : '' ?>>
                            Standard - Regular notice
                        </option>
                        <option value="sticky" <?= ($formData['type'] ?? '') === 'sticky' ? 'selected' : '' ?>>
                            Sticky - Always shown at top
                        </option>
                        <option value="timed" <?= ($formData['type'] ?? '') === 'timed' ? 'selected' : '' ?>>
                            Timed - Shows countdown until expiry
                        </option>
                        <option value="urgent" <?= ($formData['type'] ?? '') === 'urgent' ? 'selected' : '' ?>>
                            Urgent - Highlighted importance
                        </option>
                    </select>
                    <?php if (!empty($errors['type'])): ?>
                        <span class="form-error"><?= e($errors['type']) ?></span>
                    <?php endif; ?>
                    <span class="form-hint">Choose how this notice will be displayed</span>
                </div>

                <div class="form-group">
                    <label for="content" class="form-label">Content</label>
                    <textarea
                        id="content"
                        name="content"
                        class="form-textarea"
                        rows="8"
                        placeholder="Enter notice content (supports markdown: **bold**, *italic*, [links](url), lists)"
                    ><?= e($formData['content'] ?? '') ?></textarea>
                    <span class="form-hint">
                        Supports basic markdown: **bold**, *italic*, [link text](url), bullet lists (- item)
                    </span>
                </div>

                <fieldset class="form-fieldset">
                    <legend>Display Schedule (Optional)</legend>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="display_from" class="form-label">Display From</label>
                            <input
                                type="datetime-local"
                                id="display_from"
                                name="display_from"
                                class="form-input <?= !empty($errors['display_from']) ? 'error' : '' ?>"
                                value="<?= e($formData['display_from'] ?? '') ?>"
                            >
                            <?php if (!empty($errors['display_from'])): ?>
                                <span class="form-error"><?= e($errors['display_from']) ?></span>
                            <?php endif; ?>
                            <span class="form-hint">Leave empty to display immediately</span>
                        </div>

                        <div class="form-group">
                            <label for="display_to" class="form-label">
                                Display Until
                                <span class="timed-required" style="display: none;"> <span class="required">*</span></span>
                            </label>
                            <input
                                type="datetime-local"
                                id="display_to"
                                name="display_to"
                                class="form-input <?= !empty($errors['display_to']) ? 'error' : '' ?>"
                                value="<?= e($formData['display_to'] ?? '') ?>"
                            >
                            <?php if (!empty($errors['display_to'])): ?>
                                <span class="form-error"><?= e($errors['display_to']) ?></span>
                            <?php endif; ?>
                            <span class="form-hint">Leave empty for indefinite display</span>
                        </div>
                    </div>
                </fieldset>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Create Notice</button>
                    <a href="<?= url('/admin/notices') ?>" class="btn">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <div class="admin-nav-back mt-4">
        <a href="<?= url('/admin') ?>" class="btn btn-text">&larr; Back to Dashboard</a>
    </div>
</div>

<style>
.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
}

.form-fieldset {
    border: 1px solid var(--border, #e0e0e0);
    border-radius: 8px;
    padding: 1rem;
    margin: 1rem 0;
}

.form-fieldset legend {
    padding: 0 0.5rem;
    font-weight: 600;
    font-size: 0.875rem;
}

.form-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 1.5rem;
}

.required {
    color: var(--error, #D32F2F);
}
</style>

<script>
    // Toggle required indicator for display_to when type is "timed"
    document.getElementById('type').addEventListener('change', function() {
        const timedRequired = document.querySelector('.timed-required');
        const displayTo = document.getElementById('display_to');

        if (this.value === 'timed') {
            timedRequired.style.display = 'inline';
            displayTo.required = true;
        } else {
            timedRequired.style.display = 'none';
            displayTo.required = false;
        }
    });

    // Trigger on load if type is already timed
    if (document.getElementById('type').value === 'timed') {
        document.getElementById('type').dispatchEvent(new Event('change'));
    }
</script>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../../layouts/main.php';
?>
