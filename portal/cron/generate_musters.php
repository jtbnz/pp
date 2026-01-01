#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Generate Future Musters - Cron Job
 *
 * Run monthly on the 1st at 2am NZST to generate invisible musters
 * in DLB for upcoming training nights (12 months ahead by default).
 *
 * Crontab entry (runs at 2am on the 1st of each month NZST):
 * 0 2 1 * * TZ=Pacific/Auckland /usr/bin/php /path/to/portal/cron/generate_musters.php >> /path/to/portal/data/logs/cron.log 2>&1
 *
 * Or using TZ environment variable:
 * 0 2 1 * * cd /path/to/portal && TZ=Pacific/Auckland php cron/generate_musters.php
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
    echo "[{$timestamp}] [{$level}] generate_musters: {$message}\n";
}

// Main execution
cronLog('Starting generate musters job');

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

    // Get configuration
    $monthsAhead = $config['training']['generate_months_ahead'] ?? 12;

    // Create DLB client
    $dlbClient = new DlbClient(
        $config['dlb']['base_url'] ?? 'https://kiaora.tech/dlb/puke',
        $config['dlb']['api_token'],
        $config['dlb']['timeout'] ?? 30
    );

    // Create sync service
    $syncService = new SyncService($dlbClient, $db);

    // Generate future musters
    cronLog("Generating musters for the next {$monthsAhead} months");
    $results = $syncService->createFutureMusters($monthsAhead);

    // Log results
    cronLog(sprintf(
        'Results: Created: %d, Skipped: %d, Failed: %d',
        $results['created'],
        $results['skipped'],
        $results['failed']
    ));

    if (!empty($results['errors'])) {
        foreach ($results['errors'] as $error) {
            cronLog('Error: ' . $error, 'ERROR');
        }
    }

    if ($results['failed'] > 0) {
        cronLog('Generate musters job completed with errors', 'WARN');
        exit(1);
    }

    cronLog('Generate musters job completed successfully');
    exit(0);

} catch (DlbApiException $e) {
    cronLog('DLB API error: ' . $e->getSummary(), 'ERROR');
    exit(1);

} catch (Throwable $e) {
    cronLog('Unexpected error: ' . $e->getMessage(), 'ERROR');
    cronLog('Stack trace: ' . $e->getTraceAsString(), 'DEBUG');
    exit(1);
}
