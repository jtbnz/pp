<?php
declare(strict_types=1);

/**
 * Home Page Template
 *
 * Landing page for the Puke Portal application.
 */

global $config;

$pageTitle = 'Home';
$appName = $config['app_name'] ?? 'Puke Portal';
$appUrl = $config['app_url'] ?? '';
$user = currentUser();

// Start output buffering for content
ob_start();
?>

<div class="page-home">
    <?php if ($user): ?>
        <!-- Authenticated User View -->
        <section class="welcome-section">
            <h1>Welcome, <?= e($user['name']) ?></h1>
            <p class="text-secondary">Puke Volunteer Fire Brigade Portal</p>
        </section>

        <!-- Next Training Card -->
        <section class="next-training-card">
            <span class="next-training-label">Next Training</span>
            <p class="next-training-date">Monday, 6 January 2025</p>
            <p class="next-training-time">7:00 PM</p>
            <div class="next-training-actions">
                <button type="button" class="btn" style="background: rgba(255,255,255,0.2); border-color: transparent; color: white;">
                    Request Leave
                </button>
                <button type="button" class="btn" style="background: transparent; border-color: rgba(255,255,255,0.5); color: white;">
                    Add to Calendar
                </button>
            </div>
        </section>

        <!-- Upcoming Events -->
        <section class="upcoming-events mb-4">
            <div class="section-header">
                <h2>Upcoming Events</h2>
                <a href="<?= url('/calendar') ?>" class="section-link">View all</a>
            </div>
            <div class="card">
                <div class="card-body">
                    <p class="text-secondary text-center p-3">No upcoming events</p>
                </div>
            </div>
        </section>

        <!-- Notices -->
        <section class="notices-section mb-4">
            <div class="section-header">
                <h2>Notices</h2>
                <a href="<?= url('/notices') ?>" class="section-link">View all</a>
            </div>
            <div class="card">
                <div class="card-body">
                    <p class="text-secondary text-center p-3">No notices to display</p>
                </div>
            </div>
        </section>

        <!-- Leave Status -->
        <section class="leave-section">
            <div class="section-header">
                <h2>Your Leave Status</h2>
                <a href="<?= url('/leave') ?>" class="section-link">Manage</a>
            </div>
            <div class="card">
                <div class="card-body">
                    <p class="text-secondary text-center p-3">No pending leave requests</p>
                </div>
            </div>
        </section>

    <?php else: ?>
        <!-- Guest View -->
        <section class="welcome-section text-center" style="padding: 3rem 1rem;">
            <h1><?= e($appName) ?></h1>
            <p class="text-secondary mb-4">Fire brigade member portal</p>
            <a href="<?= url('/auth/login') ?>" class="btn btn-primary btn-lg">Sign In</a>
        </section>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();

// Include main layout
require __DIR__ . '/../layouts/main.php';
