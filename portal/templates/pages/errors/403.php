<?php
declare(strict_types=1);

/**
 * 403 Forbidden Error Page
 */

$pageTitle = 'Access Denied';

// Start output buffering for content
ob_start();
?>

<div class="error-page">
    <div class="error-content text-center" style="padding: 3rem 1rem;">
        <h1 style="font-size: 4rem; color: var(--color-error); margin-bottom: 1rem;">403</h1>
        <h2>Access Denied</h2>
        <p class="text-secondary mb-4">
            You don't have permission to access this page.
        </p>
        <a href="/" class="btn btn-primary">Go Home</a>
    </div>
</div>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../layouts/main.php';
