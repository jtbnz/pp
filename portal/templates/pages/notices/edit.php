<?php
declare(strict_types=1);

/**
 * Edit Notice Page
 *
 * Form to edit an existing notice (admin only).
 *
 * Variables:
 * - $notice: array - The notice data
 * - $errors: array - Validation errors
 */

global $config;

$pageTitle = $pageTitle ?? 'Edit Notice';
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';

// Format datetime values for input (convert from UTC stored in DB to local time)
$displayFrom = '';
if (!empty($notice['display_from'])) {
    $displayFrom = fromUtc($notice['display_from'], 'Y-m-d\TH:i');
}
$displayTo = '';
if (!empty($notice['display_to'])) {
    $displayTo = fromUtc($notice['display_to'], 'Y-m-d\TH:i');
}

// Start output buffering for content
ob_start();
?>

<div class="page-notice-form">
    <nav class="breadcrumb-nav mb-3">
        <a href="<?= url('/notices/' . (int)$notice['id']) ?>" class="breadcrumb-link">&larr; Back to Notice</a>
    </nav>

    <header class="page-header mb-4">
        <h1><?= e($pageTitle) ?></h1>
    </header>

    <div class="card">
        <div class="card-body">
            <form action="<?= url('/notices/' . (int)$notice['id']) ?>" method="POST" class="notice-form">
                <input type="hidden" name="_method" value="PUT">
                <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">

                <div class="form-group">
                    <label for="title" class="form-label">Title <span class="required">*</span></label>
                    <input
                        type="text"
                        id="title"
                        name="title"
                        class="form-input <?= !empty($errors['title']) ? 'error' : '' ?>"
                        value="<?= e($notice['title'] ?? '') ?>"
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
                        <option value="standard" <?= ($notice['type'] ?? 'standard') === 'standard' ? 'selected' : '' ?>>
                            Standard - Regular notice
                        </option>
                        <option value="sticky" <?= ($notice['type'] ?? '') === 'sticky' ? 'selected' : '' ?>>
                            Sticky - Always shown at top
                        </option>
                        <option value="timed" <?= ($notice['type'] ?? '') === 'timed' ? 'selected' : '' ?>>
                            Timed - Shows countdown until expiry
                        </option>
                        <option value="urgent" <?= ($notice['type'] ?? '') === 'urgent' ? 'selected' : '' ?>>
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
                    ><?= e($notice['content'] ?? '') ?></textarea>
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
                                value="<?= e($displayFrom) ?>"
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
                                value="<?= e($displayTo) ?>"
                            >
                            <?php if (!empty($errors['display_to'])): ?>
                                <span class="form-error"><?= e($errors['display_to']) ?></span>
                            <?php endif; ?>
                            <span class="form-hint">Leave empty for indefinite display</span>
                        </div>
                    </div>
                </fieldset>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="<?= url('/notices/' . (int)$notice['id']) ?>" class="btn">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <div class="danger-zone mt-5">
        <h3 class="text-error mb-3">Danger Zone</h3>
        <div class="card">
            <div class="card-body flex justify-between items-center">
                <div>
                    <strong>Delete this notice</strong>
                    <p class="text-secondary text-sm mb-0">Once deleted, this notice cannot be recovered.</p>
                </div>
                <form action="<?= url('/notices/' . (int)$notice['id']) ?>" method="POST" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this notice? This action cannot be undone.')">
                    <input type="hidden" name="_method" value="DELETE">
                    <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                    <button type="submit" class="btn btn-danger">Delete Notice</button>
                </form>
            </div>
        </div>
    </div>
</div>

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
require __DIR__ . '/../../layouts/main.php';
?>
