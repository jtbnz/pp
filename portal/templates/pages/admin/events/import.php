<?php
declare(strict_types=1);

/**
 * Admin Import Events Template
 *
 * CSV import form for bulk calendar event creation (admin only).
 */

global $config;

$pageTitle = $pageTitle ?? 'Import Calendar Events';
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';
$user = currentUser();

// Get form errors and data from session
$errors = $_SESSION['form_errors'] ?? [];
$formData = $_SESSION['form_data'] ?? [];
$previewData = $_SESSION['import_preview'] ?? null;
$importResult = $_SESSION['import_result'] ?? null;
unset($_SESSION['form_errors'], $_SESSION['form_data'], $_SESSION['import_preview'], $_SESSION['import_result']);

// Start output buffering for content
ob_start();
?>

<div class="page-admin-import-events">
    <nav class="breadcrumb-nav mb-3">
        <a href="<?= url('/admin/events') ?>" class="breadcrumb-link">&larr; Back to Events</a>
    </nav>

    <header class="page-header mb-4">
        <h1><?= e($pageTitle) ?></h1>
        <p class="page-description">Import multiple calendar events from a CSV or spreadsheet file.</p>
    </header>

    <?php if ($importResult): ?>
        <div class="alert alert-<?= $importResult['success'] ? 'success' : 'error' ?> mb-4">
            <?php if ($importResult['success']): ?>
                <strong>Import Successful!</strong>
                <p>Created <?= $importResult['created'] ?> event(s).</p>
                <?php if (!empty($importResult['skipped'])): ?>
                    <p>Skipped <?= count($importResult['skipped']) ?> duplicate(s).</p>
                <?php endif; ?>
            <?php else: ?>
                <strong>Import Failed</strong>
                <p><?= e($importResult['error'] ?? 'Unknown error occurred') ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors['file'])): ?>
        <div class="alert alert-error mb-4">
            <?= e($errors['file']) ?>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">
            <h2>CSV Format</h2>
        </div>
        <div class="card-body">
            <p>Your CSV file should have the following columns:</p>
            <table class="table-format">
                <thead>
                    <tr>
                        <th>Column</th>
                        <th>Required</th>
                        <th>Format</th>
                        <th>Example</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>title</code></td>
                        <td><span class="badge badge-required">Required</span></td>
                        <td>Text</td>
                        <td>Training Night</td>
                    </tr>
                    <tr>
                        <td><code>date</code></td>
                        <td><span class="badge badge-required">Required</span></td>
                        <td>YYYY-MM-DD or DD/MM/YYYY</td>
                        <td>2026-02-10 or 10/02/2026</td>
                    </tr>
                    <tr>
                        <td><code>start_time</code></td>
                        <td><span class="badge badge-optional">Optional</span></td>
                        <td>HH:MM (24hr) or HH:MM AM/PM</td>
                        <td>19:00 or 7:00 PM</td>
                    </tr>
                    <tr>
                        <td><code>end_time</code></td>
                        <td><span class="badge badge-optional">Optional</span></td>
                        <td>HH:MM (24hr) or HH:MM AM/PM</td>
                        <td>21:00 or 9:00 PM</td>
                    </tr>
                    <tr>
                        <td><code>description</code></td>
                        <td><span class="badge badge-optional">Optional</span></td>
                        <td>Text</td>
                        <td>Monthly practice drill</td>
                    </tr>
                    <tr>
                        <td><code>location</code></td>
                        <td><span class="badge badge-optional">Optional</span></td>
                        <td>Text</td>
                        <td>Fire Station</td>
                    </tr>
                    <tr>
                        <td><code>is_training</code></td>
                        <td><span class="badge badge-optional">Optional</span></td>
                        <td>1/0, yes/no, true/false</td>
                        <td>1</td>
                    </tr>
                    <tr>
                        <td><code>all_day</code></td>
                        <td><span class="badge badge-optional">Optional</span></td>
                        <td>1/0, yes/no, true/false</td>
                        <td>0</td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-3"><strong>Note:</strong> The first row should contain column headers. Column order doesn't matter.</p>

            <details class="mt-3">
                <summary>Download sample CSV</summary>
                <pre class="code-block mt-2">title,date,start_time,end_time,description,location,is_training
Training Night,2026-02-10,19:00,21:00,Weekly training session,Fire Station,1
Committee Meeting,2026-02-15,18:00,19:30,Monthly committee meeting,Fire Station,0
Equipment Check,2026-02-20,10:00,12:00,Quarterly equipment inspection,Fire Station,0</pre>
            </details>
        </div>
    </div>

    <?php if ($previewData): ?>
        <!-- Preview Mode -->
        <div class="card mb-4">
            <div class="card-header">
                <h2>Preview Import</h2>
                <span class="badge"><?= count($previewData['valid']) ?> valid, <?= count($previewData['errors']) ?> errors</span>
            </div>
            <div class="card-body">
                <?php if (!empty($previewData['errors'])): ?>
                    <div class="alert alert-warning mb-3">
                        <strong>Some rows have errors and will be skipped:</strong>
                        <ul class="error-list">
                            <?php foreach ($previewData['errors'] as $error): ?>
                                <li>Row <?= $error['row'] ?>: <?= e($error['message']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!empty($previewData['valid'])): ?>
                    <table class="table-preview">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Location</th>
                                <th>Training</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($previewData['valid'] as $event): ?>
                                <tr>
                                    <td><?= e($event['title']) ?></td>
                                    <td><?= date('D, j M Y', strtotime($event['date'])) ?></td>
                                    <td>
                                        <?php if (!empty($event['start_time'])): ?>
                                            <?= e($event['start_time']) ?>
                                            <?php if (!empty($event['end_time'])): ?>
                                                - <?= e($event['end_time']) ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">All day</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($event['location'] ?? '-') ?></td>
                                    <td><?= $event['is_training'] ? '&#10004;' : '' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <form action="<?= url('/admin/events/import') ?>" method="POST" class="mt-4">
                        <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="confirmed" value="1">
                        <input type="hidden" name="import_data" value="<?= e(json_encode($previewData['valid'])) ?>">

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                Import <?= count($previewData['valid']) ?> Event(s)
                            </button>
                            <a href="<?= url('/admin/events/import') ?>" class="btn">Cancel</a>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="text-error">No valid events to import. Please fix the errors and try again.</p>
                    <a href="<?= url('/admin/events/import') ?>" class="btn">Try Again</a>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <!-- Upload Form -->
        <div class="card">
            <div class="card-header">
                <h2>Upload File</h2>
            </div>
            <div class="card-body">
                <form action="<?= url('/admin/events/import/preview') ?>" method="POST" enctype="multipart/form-data" class="import-form">
                    <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">

                    <div class="form-group">
                        <label for="csv_file" class="form-label">Select CSV File <span class="required">*</span></label>
                        <input
                            type="file"
                            id="csv_file"
                            name="csv_file"
                            class="form-input-file"
                            accept=".csv,.txt"
                            required
                        >
                        <span class="form-hint">Accepts .csv files (max 1MB)</span>
                    </div>

                    <div class="form-group">
                        <label class="form-checkbox">
                            <input type="checkbox" name="skip_duplicates" value="1" checked>
                            <span class="checkbox-label">Skip events that already exist (same title and date)</span>
                        </label>
                    </div>

                    <div class="form-group">
                        <label class="form-checkbox">
                            <input type="checkbox" name="default_training" value="1">
                            <span class="checkbox-label">Mark all events as training nights by default</span>
                        </label>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Preview Import</button>
                        <a href="<?= url('/admin/events') ?>" class="btn">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="admin-nav-back mt-4">
        <a href="<?= url('/admin') ?>" class="btn btn-text">&larr; Back to Dashboard</a>
    </div>
</div>

<style>
.page-description {
    color: var(--color-text-secondary, #666);
    margin-top: 0.5rem;
}

.table-format,
.table-preview {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.table-format th,
.table-format td,
.table-preview th,
.table-preview td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid var(--border, #e0e0e0);
}

.table-format th,
.table-preview th {
    background: var(--color-background-secondary, #f5f5f5);
    font-weight: 600;
}

.table-format code {
    background: var(--color-background-secondary, #f0f0f0);
    padding: 0.125rem 0.375rem;
    border-radius: 3px;
    font-size: 0.8rem;
}

.badge {
    display: inline-block;
    padding: 0.125rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
}

.badge-required {
    background: var(--color-error, #D32F2F);
    color: white;
}

.badge-optional {
    background: var(--color-text-secondary, #666);
    color: white;
}

.code-block {
    background: var(--color-background-secondary, #f5f5f5);
    padding: 1rem;
    border-radius: 6px;
    overflow-x: auto;
    font-size: 0.8rem;
    line-height: 1.5;
}

details summary {
    cursor: pointer;
    color: var(--color-primary, #D32F2F);
    font-weight: 500;
}

.alert {
    padding: 1rem;
    border-radius: 6px;
    border-left: 4px solid;
}

.alert-success {
    background: #e8f5e9;
    border-color: #4caf50;
    color: #2e7d32;
}

.alert-error {
    background: #ffebee;
    border-color: #f44336;
    color: #c62828;
}

.alert-warning {
    background: #fff3e0;
    border-color: #ff9800;
    color: #e65100;
}

.error-list {
    margin: 0.5rem 0 0 1.5rem;
    padding: 0;
}

.error-list li {
    margin: 0.25rem 0;
}

.form-input-file {
    display: block;
    width: 100%;
    padding: 0.75rem;
    border: 2px dashed var(--border, #e0e0e0);
    border-radius: 6px;
    background: var(--color-background-secondary, #f9f9f9);
    cursor: pointer;
}

.form-input-file:hover {
    border-color: var(--color-primary, #D32F2F);
}

.form-checkbox {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.form-checkbox input[type="checkbox"] {
    width: 1.25rem;
    height: 1.25rem;
    cursor: pointer;
}

.checkbox-label {
    font-weight: 500;
}

.form-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 1.5rem;
}

.required {
    color: var(--color-error, #D32F2F);
}

.text-muted {
    color: var(--color-text-secondary, #999);
}

.text-error {
    color: var(--color-error, #D32F2F);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    border-bottom: 1px solid var(--border, #e0e0e0);
    background: var(--color-background-secondary, #f9f9f9);
}

.card-header h2 {
    margin: 0;
    font-size: 1rem;
}
</style>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../../layouts/main.php';
?>
