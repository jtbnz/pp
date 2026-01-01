#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Reveal Today's Muster - Cron Job
 *
 * Run daily at midnight NZST to reveal today's training muster in DLB.
 * This makes the muster visible in the attendance UI on training day.
 *
 * Crontab entry (runs at midnight NZST):
 * 0 0 * * * TZ=Pacific/Auckland /usr/bin/php /path/to/portal/cron/reveal_musters.php >> /path/to/portal/data/logs/cron.log 2>&1
 *
 * Or using TZ environment variable:
 * 0 0 * * * cd /path/to/portal && TZ=Pacific/Auckland php cron/reveal_musters.php
 */

// Set timezone
date_default_timezone_set('Pacific/Auckland');

// Determine the base directory
$baseDir = dirname(__DIR__);

// Load bootstrap (includes config, database, etc.)
require_once $baseDir . '/src/bootstrap.php';

// Load required classes
require_once $baseDir . '/src/Services/DlbClient.php';
require_once $baseDir . '/src/Services/SyncService.php';
require_once $baseDir . '/src/Exceptions/DlbApiException.php';

/**
 * Log a message with timestamp
 *
 * @param string $message
 * @param string $level
 */
function cronLog(string $message, string $level = 'INFO'): void
{
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] [{$level}] reveal_musters: {$message}\n";
}

// Main execution
cronLog('Starting reveal musters job');

try {
    // Check if DLB integration is enabled
    if (empty($config['dlb']['enabled'])) {
        cronLog('DLB integration is not enabled, skipping', 'WARN');
        exit(0);
    }

    if (empty($config['dlb']['api_token'])) {
        cronLog('DLB API token is not configured', 'ERROR');
        exit(1);
    }

    // Create DLB client
    $dlbClient = new DlbClient(
        $config['dlb']['base_url'] ?? 'https://kiaora.tech/dlb/puke',
        $config['dlb']['api_token'],
        $config['dlb']['timeout'] ?? 30
    );

    // Create sync service
    $syncService = new SyncService($dlbClient, $db);

    // Reveal today's muster
    $revealed = $syncService->revealTodaysMuster();

    if ($revealed) {
        cronLog('Successfully revealed muster for ' . date('Y-m-d'));
    } else {
        cronLog('No training scheduled for today (' . date('Y-m-d') . ')');
    }

    cronLog('Reveal musters job completed');
    exit(0);

} catch (DlbApiException $e) {
    cronLog('DLB API error: ' . $e->getSummary(), 'ERROR');
    exit(1);

} catch (Throwable $e) {
    cronLog('Unexpected error: ' . $e->getMessage(), 'ERROR');
    cronLog('Stack trace: ' . $e->getTraceAsString(), 'DEBUG');
    exit(1);
}
