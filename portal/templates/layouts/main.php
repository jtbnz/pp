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

// Get color blind mode preference from user or session
$colorBlindMode = false;
if ($user) {
    $prefs = json_decode($user['preferences'] ?? '{}', true) ?: [];
    $colorBlindMode = $prefs['color_blind_mode'] ?? false;
}
?>
<!DOCTYPE html>
<html lang="en" data-color-blind-mode="<?= $colorBlindMode ? 'true' : 'false' ?>">
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
    <link rel="stylesheet" href="<?= url('/assets/css/notifications.css') ?>">

    <!-- Base path for JavaScript -->
    <meta name="base-path" content="<?= e($config['base_path'] ?? '') ?>">

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
                <div class="notification-wrapper">
                    <button type="button" id="notification-bell" class="notifications-toggle" aria-label="Notifications">
                        <span class="notification-icon">&#128276;</span>
                        <span id="notification-badge" class="notification-badge" hidden>0</span>
                    </button>

                    <!-- Notification Backdrop - absorbs touches to prevent background scroll on iOS -->
                    <div id="notification-backdrop" class="notification-backdrop" hidden></div>

                    <!-- Notification Panel (Issue #26) -->
                    <div id="notification-panel" class="notification-panel">
                        <div class="notification-panel-header">
                            <h3>Notifications</h3>
                            <div class="notification-panel-actions">
                                <button type="button" data-action="mark-all-read" title="Mark all as read">&#10003;</button>
                                <button type="button" data-action="clear-all" title="Clear all">&#128465;</button>
                                <button type="button" data-action="show-settings" title="Settings">&#9881;</button>
                            </div>
                        </div>
                        <div id="notification-list" class="notification-list"></div>
                        <button type="button" id="notification-load-more" hidden>Load more</button>
                    </div>
                </div>

                <div class="user-menu">
                    <button type="button" class="user-toggle" aria-label="User menu" aria-expanded="false">
                        <?php
                        // Get first name initial (skip rank prefix like CFO, SO, FF, etc.)
                        $nameParts = preg_split('/\s+/', trim($user['name']));
                        $rankPrefixes = ['CFO', 'DCFO', 'ACFO', 'SSO', 'SO', 'SFF', 'QFF', 'FF', 'RFF', 'RCFF'];
                        $firstNameInitial = 'U';
                        foreach ($nameParts as $part) {
                            if (!in_array(strtoupper($part), $rankPrefixes, true)) {
                                $firstNameInitial = strtoupper(substr($part, 0, 1));
                                break;
                            }
                        }
                        ?>
                        <span class="user-avatar"><?= $firstNameInitial ?></span>
                    </button>
                    <div class="user-dropdown" hidden>
                        <div class="user-info">
                            <span class="user-name"><?= e($user['name']) ?></span>
                            <span class="user-role"><?= ucfirst(e($user['role'])) ?></span>
                        </div>
                        <a href="<?= url('/profile') ?>" class="dropdown-item">Profile</a>
                        <?php if (isAdmin()): ?>
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
            <?php if (canApproveLeave()): ?>
                <a href="<?= url('/leave/pending') ?>" class="nav-item <?= str_starts_with($currentPath, '/leave/pending') ? 'active' : '' ?>">
                    <span class="nav-icon">&#9989;</span>
                    <span class="nav-text">Approvals</span>
                </a>
                <?php if (!empty($config['dlb']['enabled']) && !empty($config['dlb']['base_url'])): ?>
                <a href="<?= e($config['dlb']['base_url']) ?>" class="nav-item" target="_blank" rel="noopener">
                    <span class="nav-icon">&#128203;</span>
                    <span class="nav-text">Attendance</span>
                    <span class="nav-external">&#8599;</span>
                </a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (hasActualRole('admin')): ?>
                <hr class="nav-divider">
                <a href="<?= url('/admin') ?>" class="nav-item <?= str_starts_with($currentPath, '/admin') ? 'active' : '' ?>">
                    <span class="nav-icon">&#9881;</span>
                    <span class="nav-text">Admin</span>
                </a>
            <?php endif; ?>
            <?php if ($user['role'] === 'superadmin' && !isViewingAs()): ?>
                <hr class="nav-divider">
                <div class="view-as-selector">
                    <span class="view-as-label">View As:</span>
                    <form action="<?= url('/admin/view-as') ?>" method="POST" class="view-as-select-form">
                        <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                        <select name="role" class="view-as-select" onchange="this.form.submit()">
                            <option value="">Select role...</option>
                            <option value="firefighter">Firefighter</option>
                            <option value="officer">Officer</option>
                        </select>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </nav>

    <!-- Sidebar overlay for mobile -->
    <div class="sidebar-overlay" hidden></div>
    <?php endif; ?>

    <!-- View-as banner (shown when superadmin is viewing as another role) -->
    <?php if ($user && isViewingAs()): ?>
    <div class="view-as-banner">
        <div class="view-as-content">
            <span class="view-as-icon">&#128064;</span>
            <span class="view-as-text">
                Viewing as <strong><?= ucfirst(e(getViewAsRole())) ?></strong>
                <span class="view-as-readonly">(Read-only mode)</span>
                <?php
                $expiresAt = getViewAsExpires();
                if ($expiresAt) {
                    $minutesLeft = max(0, (int)ceil(($expiresAt - time()) / 60));
                ?>
                <span class="view-as-timer"><?= $minutesLeft ?> min remaining</span>
                <?php } ?>
            </span>
            <form action="<?= url('/admin/view-as/stop') ?>" method="POST" class="view-as-form">
                <input type="hidden" name="_csrf_token" value="<?= csrfToken() ?>">
                <button type="submit" class="view-as-exit">Exit View</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Main content area -->
    <main id="main-content" class="main-content <?= $user ? 'has-sidebar' : '' ?> <?= isViewingAs() ? 'has-view-as-banner' : '' ?>">
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
    <?php
    // Handle pending remember token from login - store in localStorage for PWA
    if (isset($_SESSION['pending_remember_token'])): ?>
    <script>
    (function() {
        try {
            localStorage.setItem('puke_remember_token', '<?= e($_SESSION['pending_remember_token']) ?>');
            console.log('[Auth] Remember token stored in localStorage for PWA');
        } catch (e) {
            console.error('[Auth] Failed to store remember token:', e);
        }
    })();
    </script>
    <?php
        // Clear the token from session after outputting it
        unset($_SESSION['pending_remember_token']);
    endif;
    ?>
    <script src="<?= url('/assets/js/offline-storage.js') ?>"></script>
    <script src="<?= url('/assets/js/app.js') ?>"></script>
    <?php if ($user): ?>
    <script src="<?= url('/assets/js/notification-center.js') ?>"></script>
    <?php endif; ?></script>

    <?php if (isset($extraScripts)): ?>
        <?= $extraScripts ?>
    <?php endif; ?>
</body>
</html>
