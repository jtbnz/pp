#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Cleanup Old Notifications - Cron Job
 *
 * Run daily to remove notifications older than 30 days.
 * Issue #26 - Notification Center
 *
 * Crontab entry (runs at 3:30am daily NZST):
 * 30 3 * * * TZ=Pacific/Auckland /usr/bin/php /path/to/portal/cron/cleanup_notifications.php >> /path/to/portal/data/logs/cron.log 2>&1
 */

// Set timezone
date_default_timezone_set('Pacific/Auckland');

// Determine the base directory
$baseDir = dirname(__DIR__);

// Load bootstrap (includes config, database, etc.)
require_once $baseDir . '/src/bootstrap.php';

use Portal\Services\NotificationService;

/**
 * Log a message with timestamp
 *
 * @param string $message
 * @param string $level
 */
function cronLog(string $message, string $level = 'INFO'): void
{
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] [{$level}] cleanup_notifications: {$message}\n";
}

// Main execution
cronLog('Starting notification cleanup job');

try {
    global $db, $config;

    $basePath = $config['base_path'] ?? '';
    $notificationService = new NotificationService($db, $basePath);

    // Delete notifications older than 30 days
    $retentionDays = 30;
    cronLog("Deleting notifications older than {$retentionDays} days...");

    $deletedCount = $notificationService->deleteOlderThan($retentionDays);

    cronLog("Deleted {$deletedCount} old notifications");
    cronLog('Notification cleanup job completed successfully');

    exit(0);
} catch (\Exception $e) {
    cronLog('Error: ' . $e->getMessage(), 'ERROR');
    cronLog('Stack trace: ' . $e->getTraceAsString(), 'ERROR');
    exit(1);
}
