<?php
declare(strict_types=1);

/**
 * CacheClear Command
 *
 * Clears application caches including OPcache, file caches, and compiled views.
 *
 * Usage: php artisan cache:clear [--opcache] [--files] [--all]
 */

namespace Portal\Commands;

class CacheClear
{
    private \PDO $db;
    private array $config;
    private string $rootPath;

    public function __construct(\PDO $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->rootPath = dirname(__DIR__, 2);
    }

    /**
     * Execute the command
     */
    public function execute(array $args): int
    {
        $options = $this->parseArgs($args);

        $clearOpcache = isset($options['opcache']) || isset($options['all']) || empty($options);
        $clearFiles = isset($options['files']) || isset($options['all']) || empty($options);

        $this->info('Cache Clear');
        $this->info('===========');
        $this->info('');

        $totalCleared = 0;

        // ====================================================================
        // Clear OPcache
        // ====================================================================

        if ($clearOpcache) {
            $this->info('Clearing OPcache...');

            if (function_exists('opcache_reset')) {
                if (opcache_reset()) {
                    $this->success('  OPcache cleared successfully.');
                    $totalCleared++;
                } else {
                    $this->error('  Failed to clear OPcache.');
                }
            } else {
                $this->info('  OPcache not available (extension not loaded).');
            }
        }

        // ====================================================================
        // Clear file caches
        // ====================================================================

        if ($clearFiles) {
            $this->info('');
            $this->info('Clearing file caches...');

            // Cache directory
            $cacheDir = $this->rootPath . '/data/cache';
            if (is_dir($cacheDir)) {
                $count = $this->clearDirectory($cacheDir);
                $this->success("  Cleared {$count} file(s) from data/cache/");
                $totalCleared += $count;
            } else {
                $this->info('  Cache directory does not exist.');
            }

            // Temporary files
            $tmpDir = $this->rootPath . '/tmp';
            if (is_dir($tmpDir)) {
                $count = $this->clearDirectory($tmpDir);
                $this->success("  Cleared {$count} file(s) from tmp/");
                $totalCleared += $count;
            }

            // PHP session files (if stored in custom location)
            $sessionDir = $this->rootPath . '/data/sessions';
            if (is_dir($sessionDir)) {
                $count = $this->clearDirectory($sessionDir);
                $this->success("  Cleared {$count} session file(s)");
                $totalCleared += $count;
            }
        }

        // ====================================================================
        // Clear realpath cache
        // ====================================================================

        $this->info('');
        $this->info('Clearing realpath cache...');
        clearstatcache(true);
        $this->success('  Realpath cache cleared.');

        // ====================================================================
        // Invalidate preload if configured
        // ====================================================================

        if (function_exists('opcache_get_status')) {
            $status = opcache_get_status(false);
            if ($status && isset($status['preload_statistics']['scripts'])) {
                $this->info('');
                $this->info('Note: Preloaded scripts detected. Restart PHP-FPM to clear preload cache.');
            }
        }

        // ====================================================================
        // Summary
        // ====================================================================

        $this->info('');
        $this->info('===========');
        $this->success("Cache clear complete. Items cleared: {$totalCleared}");

        return 0;
    }

    /**
     * Clear all files in a directory (but keep the directory)
     */
    private function clearDirectory(string $dir): int
    {
        $count = 0;

        if (!is_dir($dir)) {
            return 0;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                // Skip .gitkeep files
                if ($file->getFilename() === '.gitkeep') {
                    continue;
                }
                if (@unlink($file->getPathname())) {
                    $count++;
                }
            }
        }

        return $count;
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
        return 'Clear application caches';
    }

    /**
     * Get command usage
     */
    public static function getUsage(): string
    {
        return <<<USAGE
Usage: php artisan cache:clear [options]

Options:
  --opcache         Clear only OPcache
  --files           Clear only file caches
  --all             Clear all caches (default if no options specified)

Examples:
  php artisan cache:clear
  php artisan cache:clear --opcache
  php artisan cache:clear --files
USAGE;
    }
}
