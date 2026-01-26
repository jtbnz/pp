<?php
declare(strict_types=1);

/**
 * SessionCleanup Command
 *
 * Cleans up expired sessions and rate limit records from the database.
 *
 * Usage: php artisan session:clear [--dry-run]
 */

namespace Portal\Commands;

class SessionCleanup
{
    private \PDO $db;
    private array $config;

    public function __construct(\PDO $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Execute the command
     */
    public function execute(array $args): int
    {
        $options = $this->parseArgs($args);
        $dryRun = isset($options['dry-run']);

        $this->info('Session and Rate Limit Cleanup');
        $this->info('==============================');
        if ($dryRun) {
            $this->info('Mode: DRY RUN (no changes will be made)');
        }
        $this->info('');

        $now = date('Y-m-d H:i:s');
        $totalCleaned = 0;

        // ====================================================================
        // Clean expired sessions
        // ====================================================================

        $this->info('Cleaning expired sessions...');

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM sessions WHERE expires_at < ?');
        $stmt->execute([$now]);
        $expiredSessions = (int) $stmt->fetchColumn();

        if ($expiredSessions > 0) {
            if (!$dryRun) {
                $stmt = $this->db->prepare('DELETE FROM sessions WHERE expires_at < ?');
                $stmt->execute([$now]);
            }
            $this->success("  Removed: {$expiredSessions} expired session(s)");
            $totalCleaned += $expiredSessions;
        } else {
            $this->info('  No expired sessions found.');
        }

        // ====================================================================
        // Clean expired rate limits
        // ====================================================================

        $this->info('');
        $this->info('Cleaning expired rate limits...');

        // Rate limits older than decay time
        $decayMinutes = $this->config['rate_limit']['decay_minutes'] ?? 60;
        $decayTime = date('Y-m-d H:i:s', strtotime("-{$decayMinutes} minutes"));

        $stmt = $this->db->prepare('
            SELECT COUNT(*) FROM rate_limits
            WHERE (locked_until IS NULL AND first_attempt_at < ?)
               OR (locked_until IS NOT NULL AND locked_until < ?)
        ');
        $stmt->execute([$decayTime, $now]);
        $expiredRateLimits = (int) $stmt->fetchColumn();

        if ($expiredRateLimits > 0) {
            if (!$dryRun) {
                $stmt = $this->db->prepare('
                    DELETE FROM rate_limits
                    WHERE (locked_until IS NULL AND first_attempt_at < ?)
                       OR (locked_until IS NOT NULL AND locked_until < ?)
                ');
                $stmt->execute([$decayTime, $now]);
            }
            $this->success("  Removed: {$expiredRateLimits} expired rate limit(s)");
            $totalCleaned += $expiredRateLimits;
        } else {
            $this->info('  No expired rate limits found.');
        }

        // ====================================================================
        // Clean expired invite tokens
        // ====================================================================

        $this->info('');
        $this->info('Cleaning expired invite tokens...');

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM invite_tokens WHERE expires_at < ? AND used_at IS NULL');
        $stmt->execute([$now]);
        $expiredInvites = (int) $stmt->fetchColumn();

        if ($expiredInvites > 0) {
            if (!$dryRun) {
                $stmt = $this->db->prepare('DELETE FROM invite_tokens WHERE expires_at < ? AND used_at IS NULL');
                $stmt->execute([$now]);
            }
            $this->success("  Removed: {$expiredInvites} expired invite token(s)");
            $totalCleaned += $expiredInvites;
        } else {
            $this->info('  No expired invite tokens found.');
        }

        // ====================================================================
        // Clean old audit log entries (keep last 90 days)
        // ====================================================================

        $this->info('');
        $this->info('Cleaning old audit log entries (older than 90 days)...');

        $auditCutoff = date('Y-m-d H:i:s', strtotime('-90 days'));

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM audit_log WHERE created_at < ?');
        $stmt->execute([$auditCutoff]);
        $oldAuditEntries = (int) $stmt->fetchColumn();

        if ($oldAuditEntries > 0) {
            if (!$dryRun) {
                $stmt = $this->db->prepare('DELETE FROM audit_log WHERE created_at < ?');
                $stmt->execute([$auditCutoff]);
            }
            $this->success("  Removed: {$oldAuditEntries} old audit log entry/entries");
            $totalCleaned += $oldAuditEntries;
        } else {
            $this->info('  No old audit log entries found.');
        }

        // ====================================================================
        // Summary
        // ====================================================================

        $this->info('');
        $this->info('==============================');
        $this->success("Total records cleaned: {$totalCleaned}");

        if ($dryRun) {
            $this->info('');
            $this->info('DRY RUN: No changes were made. Remove --dry-run to apply changes.');
        }

        // Optimize database if we deleted a lot
        if (!$dryRun && $totalCleaned > 100) {
            $this->info('');
            $this->info('Optimizing database...');
            $this->db->exec('VACUUM');
            $this->success('  Database optimized.');
        }

        return 0;
    }

    /**
     * Parse command line arguments
     */
    private function parseArgs(array $argv): array
    {
        $args = [];
        foreach ($argv as $arg) {
            if (preg_match('/^--([^=]+)=(.*)$/', $arg, $matches)) {
                $args[$matches[1]] = $matches[2];
            } elseif (preg_match('/^--([^=]+)$/', $arg, $matches)) {
                $args[$matches[1]] = true;
            }
        }
        return $args;
    }

    /**
     * Output methods
     */
    private function info(string $message): void
    {
        echo $message . PHP_EOL;
    }

    private function success(string $message): void
    {
        echo "\033[0;32m{$message}\033[0m" . PHP_EOL;
    }

    private function error(string $message): void
    {
        echo "\033[0;31mError: {$message}\033[0m" . PHP_EOL;
    }

    /**
     * Get command description
     */
    public static function getDescription(): string
    {
        return 'Clean expired sessions, rate limits, and invite tokens';
    }

    /**
     * Get command usage
     */
    public static function getUsage(): string
    {
        return <<<USAGE
Usage: php artisan session:clear [options]

Options:
  --dry-run         Show what would be deleted without making changes

This command cleans up:
  - Expired sessions
  - Expired rate limit records
  - Expired invite tokens
  - Audit log entries older than 90 days

Examples:
  php artisan session:clear
  php artisan session:clear --dry-run
USAGE;
    }
}
