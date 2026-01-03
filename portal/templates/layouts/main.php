<?php
declare(strict_types=1);

/**
 * Main Layout Template
 *
 * Base layout for all pages. Variables available:
 * - $appName: Application name
 * - $appUrl: Application URL
 * - $pageTitle: Page-specific title (optional)
 * - $content: Page content (set via output buffering)
 */

$pageTitle = $pageTitle ?? '';
$fullTitle = $pageTitle ? "{$pageTitle} - {$appName}" : $appName;
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="<?= e($config['theme']['primary_color'] ?? '#D32F2F') ?>">
    <meta name="description" content="Puke Volunteer Fire Brigade member portal">

    <!-- PWA -->
    <link rel="manifest" href="<?= url('/manifest.json') ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= e($appName) ?>">
    <link rel="apple-touch-icon" href="<?= url('/assets/icons/icon-192.svg') ?>">

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="<?= url('/assets/icons/favicon-32.svg') ?>">
    <link rel="icon" type="image/svg+xml" sizes="16x16" href="<?= url('/assets/icons/favicon-16.svg') ?>">

    <title><?= e($fullTitle) ?></title>

    <!-- Styles -->
    <link rel="stylesheet" href="<?= url('/assets/css/app.css') ?>">

    <!-- Preload critical fonts if any -->
    <!-- <link rel="preload" href="/assets/fonts/..." as="font" type="font/woff2" crossorigin> -->

    <?php if (isset($extraHead)): ?>
        <?= $extraHead ?>
    <?php endif; ?>
</head>
<body class="<?= $user ? 'authenticated' : 'guest' ?>">
    <!-- Offline indicator -->
    <div id="offline-indicator" class="offline-indicator" hidden>
        <span class="offline-icon">&#9888;</span>
        <span>You're offline</span>
    </div>

    <!-- Skip to main content for accessibility -->
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <?php if ($user): ?>
    <!-- Header for authenticated users -->
    <header class="app-header">
        <div class="header-content">
            <button type="button" class="menu-toggle" aria-label="Toggle menu" aria-expanded="false">
                <span class="hamburger-icon"></span>
            </button>

            <a href="<?= url('/') ?>" class="logo">
                <?php if (!empty($config['theme']['logo_url'] ?? '')): ?>
                    <img src="<?= e($config['theme']['logo_url']) ?>" alt="<?= e($appName) ?>" class="logo-img">
                <?php else: ?>
                    <span class="logo-text"><?= e($appName) ?></span>
                <?php endif; ?>
            </a>

            <div class="header-actions">
                <button type="button" class="notifications-toggle" aria-label="Notifications">
                    <span class="notification-icon">&#128276;</span>
                    <span class="notification-badge" hidden>0</span>
                </button>

                <div class="user-menu">
                    <button type="button" class="user-toggle" aria-label="User menu" aria-expanded="false">
                        <span class="user-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></span>
                    </button>
                    <div class="user-dropdown" hidden>
                        <div class="user-info">
                            <span class="user-name"><?= e($user['name']) ?></span>
                            <span class="user-role"><?= ucfirst(e($user['role'])) ?></span>
                        </div>
                        <a href="<?= url('/profile') ?>" class="dropdown-item">Profile</a>
                        <?php if (hasRole('admin')): ?>
                            <a href="<?= url('/admin') ?>" class="dropdown-item">Admin</a>
                        <?php endif; ?>
                        <form action="<?= url('/auth/logout') ?>" method="POST" class="logout-form">
                            <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                            <button type="submit" class="dropdown-item logout-btn">Logout</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Navigation sidebar -->
    <?php
    // Get current path without base_path for active state checking
    $currentPath = $_SERVER['REQUEST_URI'];
    $basePath = $config['base_path'] ?? '';
    if ($basePath !== '' && str_starts_with($currentPath, $basePath)) {
        $currentPath = substr($currentPath, strlen($basePath)) ?: '/';
    }
    // Remove query string
    if (($pos = strpos($currentPath, '?')) !== false) {
        $currentPath = substr($currentPath, 0, $pos);
    }
    ?>
    <nav class="sidebar" aria-label="Main navigation">
        <div class="sidebar-content">
            <a href="<?= url('/') ?>" class="nav-item <?= ($currentPath === '/') ? 'active' : '' ?>">
                <span class="nav-icon">&#127968;</span>
                <span class="nav-text">Home</span>
            </a>
            <a href="<?= url('/calendar') ?>" class="nav-item <?= str_starts_with($currentPath, '/calendar') ? 'active' : '' ?>">
                <span class="nav-icon">&#128197;</span>
                <span class="nav-text">Calendar</span>
            </a>
            <a href="<?= url('/notices') ?>" class="nav-item <?= str_starts_with($currentPath, '/notices') ? 'active' : '' ?>">
                <span class="nav-icon">&#128240;</span>
                <span class="nav-text">Notices</span>
            </a>
            <a href="<?= url('/leave') ?>" class="nav-item <?= str_starts_with($currentPath, '/leave') ? 'active' : '' ?>">
                <span class="nav-icon">&#128198;</span>
                <span class="nav-text">Leave</span>
            </a>
            <a href="<?= url('/polls') ?>" class="nav-item <?= str_starts_with($currentPath, '/polls') ? 'active' : '' ?>">
                <span class="nav-icon">&#128202;</span>
                <span class="nav-text">Polls</span>
            </a>
            <?php if (hasRole('officer')): ?>
                <a href="<?= url('/leave/pending') ?>" class="nav-item <?= str_starts_with($currentPath, '/leave/pending') ? 'active' : '' ?>">
                    <span class="nav-icon">&#9989;</span>
                    <span class="nav-text">Approvals</span>
                </a>
            <?php endif; ?>
            <?php if (hasRole('admin')): ?>
                <hr class="nav-divider">
                <a href="<?= url('/admin') ?>" class="nav-item <?= str_starts_with($currentPath, '/admin') ? 'active' : '' ?>">
                    <span class="nav-icon">&#9881;</span>
                    <span class="nav-text">Admin</span>
                </a>
            <?php endif; ?>
        </div>
    </nav>

    <!-- Sidebar overlay for mobile -->
    <div class="sidebar-overlay" hidden></div>
    <?php endif; ?>

    <!-- Main content area -->
    <main id="main-content" class="main-content <?= $user ? 'has-sidebar' : '' ?>">
        <?php if (isset($flashMessage)): ?>
            <div class="flash-message flash-<?= e($flashType ?? 'info') ?>">
                <?= e($flashMessage) ?>
                <button type="button" class="flash-dismiss" aria-label="Dismiss">&times;</button>
            </div>
        <?php endif; ?>

        <?php if (isset($content)): ?>
            <?= $content ?>
        <?php endif; ?>
    </main>

    <?php if ($user): ?>
    <!-- Bottom navigation for mobile -->
    <nav class="bottom-nav" aria-label="Mobile navigation">
        <a href="<?= url('/') ?>" class="bottom-nav-item <?= ($currentPath === '/') ? 'active' : '' ?>">
            <span class="nav-icon">&#127968;</span>
            <span class="nav-label">Home</span>
        </a>
        <a href="<?= url('/calendar') ?>" class="bottom-nav-item <?= str_starts_with($currentPath, '/calendar') ? 'active' : '' ?>">
            <span class="nav-icon">&#128197;</span>
            <span class="nav-label">Calendar</span>
        </a>
        <a href="<?= url('/notices') ?>" class="bottom-nav-item <?= str_starts_with($currentPath, '/notices') ? 'active' : '' ?>">
            <span class="nav-icon">&#128240;</span>
            <span class="nav-label">Notices</span>
        </a>
        <a href="<?= url('/leave') ?>" class="bottom-nav-item <?= str_starts_with($currentPath, '/leave') ? 'active' : '' ?>">
            <span class="nav-icon">&#128198;</span>
            <span class="nav-label">Leave</span>
        </a>
        <a href="<?= url('/polls') ?>" class="bottom-nav-item <?= str_starts_with($currentPath, '/polls') ? 'active' : '' ?>">
            <span class="nav-icon">&#128202;</span>
            <span class="nav-label">Polls</span>
        </a>
    </nav>
    <?php endif; ?>

    <!-- Toast notifications container -->
    <div id="toast-container" class="toast-container" aria-live="polite"></div>

    <!-- Scripts -->
    <script>window.BASE_PATH = '<?= e($config['base_path'] ?? '') ?>';</script>
    <script src="<?= url('/assets/js/offline-storage.js') ?>"></script>
    <script src="<?= url('/assets/js/app.js') ?>"></script>

    <?php if (isset($extraScripts)): ?>
        <?= $extraScripts ?>
    <?php endif; ?>
</body>
</html>
