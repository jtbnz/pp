#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Cleanup Sessions and Old Data - Cron Job
 *
 * Run daily to:
 * - Remove expired sessions
 * - Remove old audit logs (older than 1 year)
 * - Clean up expired magic link tokens
 * - Remove old sync logs (older than 30 days)
 *
 * Crontab entry (runs at 3am daily NZST):
 * 0 3 * * * TZ=Pacific/Auckland /usr/bin/php /path/to/portal/cron/cleanup_sessions.php >> /path/to/portal/data/logs/cron.log 2>&1
 *
 * Or using TZ environment variable:
 * 0 3 * * * cd /path/to/portal && TZ=Pacific/Auckland php cron/cleanup_sessions.php
 */

// Set timezone
date_default_timezone_set('Pacific/Auckland');

// Determine the base directory
$baseDir = dirname(__DIR__);

// Load bootstrap (includes config, database, etc.)
require_once $baseDir . '/src/bootstrap.php';

/**
 * Log a message with timestamp
 *
 * @param string $message
 * @param string $level
 */
function cronLog(string $message, string $level = 'INFO'): void
{
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] [{$level}] cleanup_sessions: {$message}\n";
}

// Main execution
cronLog('Starting cleanup job');

$totalDeleted = 0;
$errors = [];

try {
    // 1. Clean up expired sessions
    cronLog('Cleaning up expired sessions...');

    $stmt = $db->prepare("
        DELETE FROM sessions
        WHERE expires_at < datetime('now', 'localtime')
    ");
    $stmt->execute();
    $sessionsDeleted = $stmt->rowCount();
    $totalDeleted += $sessionsDeleted;
    cronLog("Deleted {$sessionsDeleted} expired sessions");

} catch (PDOException $e) {
    $errors[] = 'Sessions: ' . $e->getMessage();
    cronLog('Error cleaning sessions: ' . $e->getMessage(), 'ERROR');
}

try {
    // 2. Clean up old audit logs (older than 1 year)
    cronLog('Cleaning up old audit logs...');
    $auditExpiry = date('Y-m-d H:i:s', strtotime('-1 year'));

    $stmt = $db->prepare("
        DELETE FROM audit_log
        WHERE created_at < ?
    ");
    $stmt->execute([$auditExpiry]);
    $auditDeleted = $stmt->rowCount();
    $totalDeleted += $auditDeleted;
    cronLog("Deleted {$auditDeleted} old audit log entries");

} catch (PDOException $e) {
    $errors[] = 'Audit logs: ' . $e->getMessage();
    cronLog('Error cleaning audit logs: ' . $e->getMessage(), 'ERROR');
}

try {
    // 3. Clean up expired magic link tokens
    cronLog('Cleaning up expired magic link tokens...');

    $stmt = $db->prepare("
        DELETE FROM magic_links
        WHERE expires_at < datetime('now', 'localtime')
            OR used_at IS NOT NULL
    ");
    $stmt->execute();
    $tokensDeleted = $stmt->rowCount();
    $totalDeleted += $tokensDeleted;
    cronLog("Deleted {$tokensDeleted} expired/used magic link tokens");

} catch (PDOException $e) {
    $errors[] = 'Magic links: ' . $e->getMessage();
    cronLog('Error cleaning magic links: ' . $e->getMessage(), 'ERROR');
}

try {
    // 4. Clean up old sync logs (older than 30 days)
    cronLog('Cleaning up old sync logs...');
    $syncExpiry = date('Y-m-d H:i:s', strtotime('-30 days'));

    $stmt = $db->prepare("
        DELETE FROM sync_logs
        WHERE created_at < ?
    ");
    $stmt->execute([$syncExpiry]);
    $syncDeleted = $stmt->rowCount();
    $totalDeleted += $syncDeleted;
    cronLog("Deleted {$syncDeleted} old sync log entries");

} catch (PDOException $e) {
    $errors[] = 'Sync logs: ' . $e->getMessage();
    cronLog('Error cleaning sync logs: ' . $e->getMessage(), 'ERROR');
}

try {
    // 5. Clean up old rate limit records
    cronLog('Cleaning up old rate limit records...');
    $rateLimitExpiry = date('Y-m-d H:i:s', strtotime('-1 day'));

    $stmt = $db->prepare("
        DELETE FROM rate_limits
        WHERE first_attempt_at < ?
            AND locked_until IS NULL
    ");
    $stmt->execute([$rateLimitExpiry]);
    $rateLimitDeleted = $stmt->rowCount();
    $totalDeleted += $rateLimitDeleted;
    cronLog("Deleted {$rateLimitDeleted} old rate limit records");

} catch (PDOException $e) {
    $errors[] = 'Rate limits: ' . $e->getMessage();
    cronLog('Error cleaning rate limits: ' . $e->getMessage(), 'ERROR');
}

try {
    // 6. Clean up expired push notification subscriptions
    cronLog('Cleaning up invalid push subscriptions...');

    // Remove subscriptions that haven't been used in 90 days
    $pushExpiry = date('Y-m-d H:i:s', strtotime('-90 days'));

    $stmt = $db->prepare("
        DELETE FROM push_subscriptions
        WHERE (last_used_at IS NOT NULL AND last_used_at < ?)
            OR (last_used_at IS NULL AND created_at < ?)
    ");
    $stmt->execute([$pushExpiry, $pushExpiry]);
    $pushDeleted = $stmt->rowCount();
    $totalDeleted += $pushDeleted;
    cronLog("Deleted {$pushDeleted} old push subscriptions");

} catch (PDOException $e) {
    $errors[] = 'Push subscriptions: ' . $e->getMessage();
    cronLog('Error cleaning push subscriptions: ' . $e->getMessage(), 'ERROR');
}

try {
    // 7. Vacuum the database to reclaim space
    cronLog('Vacuuming database...');
    $db->exec('VACUUM');
    cronLog('Database vacuum completed');

} catch (PDOException $e) {
    $errors[] = 'Vacuum: ' . $e->getMessage();
    cronLog('Error vacuuming database: ' . $e->getMessage(), 'ERROR');
}

// Summary
cronLog(sprintf('Cleanup completed. Total records deleted: %d', $totalDeleted));

if (!empty($errors)) {
    cronLog('Cleanup completed with ' . count($errors) . ' error(s)', 'WARN');
    foreach ($errors as $error) {
        cronLog('Error: ' . $error, 'ERROR');
    }
    exit(1);
}

cronLog('Cleanup job completed successfully');
exit(0);
