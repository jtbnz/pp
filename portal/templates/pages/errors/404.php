<?php
declare(strict_types=1);

/**
 * 404 Not Found Error Page
 */

$pageTitle = 'Page Not Found';

// Start output buffering for content
ob_start();
?>

<div class="error-page">
    <div class="error-content text-center" style="padding: 3rem 1rem;">
        <h1 style="font-size: 4rem; color: var(--color-primary); margin-bottom: 1rem;">404</h1>
        <h2>Page Not Found</h2>
        <p class="text-secondary mb-4">
            <?= isset($message) ? e($message) : "The page you're looking for doesn't exist." ?>
        </p>
        <a href="/" class="btn btn-primary">Go Home</a>
    </div>
</div>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../../layouts/main.php';
