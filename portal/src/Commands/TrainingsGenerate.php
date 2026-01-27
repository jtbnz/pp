<?php
declare(strict_types=1);

/**
 * TrainingsGenerate Command
 *
 * Generates training night events for the specified period.
 * Automatically adjusts for Auckland public holidays (moves Monday trainings to Tuesday).
 *
 * Usage: php artisan trainings:generate [--months=12] [--brigade=1] [--dry-run]
 */

namespace Portal\Commands;

class TrainingsGenerate
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

        $months = (int) ($options['months'] ?? $this->config['training']['generate_months_ahead'] ?? 12);
        $brigadeId = (int) ($options['brigade'] ?? 1);
        $dryRun = isset($options['dry-run']);

        // Get brigade
        $stmt = $this->db->prepare('SELECT * FROM brigades WHERE id = ?');
        $stmt->execute([$brigadeId]);
        $brigade = $stmt->fetch();

        if (!$brigade) {
            $this->error("Brigade not found with ID: {$brigadeId}");
            return 1;
        }

        $trainingDay = (int) ($brigade['training_day'] ?? 1); // 1 = Monday
        $trainingTime = $brigade['training_time'] ?? '19:00';
        $durationHours = $this->config['training']['duration_hours'] ?? 2;

        $this->info("Generating training nights for: {$brigade['name']}");
        $this->info("  Training day: " . $this->getDayName($trainingDay));
        $this->info("  Training time: {$trainingTime}");
        $this->info("  Duration: {$durationHours} hours");
        $this->info("  Period: {$months} months");
        if ($dryRun) {
            $this->info("  Mode: DRY RUN (no changes will be made)");
        }
        $this->info("");

        // Load Auckland public holidays
        $holidays = $this->getPublicHolidays($brigadeId);

        // Generate dates
        $startDate = new \DateTime('now', new \DateTimeZone('Pacific/Auckland'));
        $endDate = (clone $startDate)->modify("+{$months} months");

        $created = 0;
        $skipped = 0;
        $adjusted = 0;

        $current = clone $startDate;

        // Find first occurrence of training day
        while ((int) $current->format('N') !== $trainingDay) {
            $current->modify('+1 day');
        }

        while ($current <= $endDate) {
            $dateStr = $current->format('Y-m-d');
            $originalDate = $dateStr;
            $wasAdjusted = false;

            // Check if this is a public holiday (Auckland or national)
            if ($this->isPublicHoliday($dateStr, $holidays)) {
                // Move to next day (Tuesday)
                $adjustedDate = (clone $current)->modify('+1 day');
                $dateStr = $adjustedDate->format('Y-m-d');
                $wasAdjusted = true;
                $this->info("  Adjusted: {$originalDate} (holiday) -> {$dateStr}");
            }

            // Check if training already exists for this date
            $stmt = $this->db->prepare('
                SELECT id FROM events
                WHERE brigade_id = ?
                AND DATE(start_time) = ?
                AND (is_training = 1 OR event_type = \'training\')
            ');
            $stmt->execute([$brigadeId, $dateStr]);

            if ($stmt->fetch()) {
                $skipped++;
            } else {
                // Create training event
                $startTime = $dateStr . ' ' . $trainingTime . ':00';
                $endTime = (new \DateTime($startTime))->modify("+{$durationHours} hours")->format('Y-m-d H:i:s');

                if (!$dryRun) {
                    $stmt = $this->db->prepare('
                        INSERT INTO events (brigade_id, title, start_time, end_time, is_training, is_visible)
                        VALUES (?, ?, ?, ?, 1, 1)
                    ');
                    $stmt->execute([
                        $brigadeId,
                        'Training Night',
                        $startTime,
                        $endTime
                    ]);
                }

                $created++;
                if ($wasAdjusted) {
                    $adjusted++;
                }

                $this->success("  Created: {$dateStr} {$trainingTime}" . ($wasAdjusted ? " (adjusted from {$originalDate})" : ''));
            }

            // Move to next week
            $current->modify('+1 week');
        }

        $this->info("");
        $this->info("Summary:");
        $this->success("  Created: {$created}");
        $this->info("  Skipped (already exists): {$skipped}");
        $this->info("  Adjusted for holidays: {$adjusted}");

        if ($dryRun) {
            $this->info("");
            $this->info("DRY RUN: No changes were made. Remove --dry-run to apply changes.");
        }

        return 0;
    }

    /**
     * Get public holidays from database
     */
    private function getPublicHolidays(int $brigadeId): array
    {
        $currentYear = (int) date('Y');
        $nextYear = $currentYear + 1;

        // First try to get from database
        $stmt = $this->db->prepare('
            SELECT date, name, region FROM public_holidays
            WHERE year IN (?, ?) AND (region = ? OR region = ?)
        ');
        $stmt->execute([$currentYear, $nextYear, 'auckland', 'national']);
        $holidays = $stmt->fetchAll();

        if (!empty($holidays)) {
            return array_column($holidays, 'date');
        }

        // Fallback: generate Auckland/NZ holidays
        return $this->generateHolidays($currentYear, $nextYear);
    }

    /**
     * Generate NZ/Auckland public holidays for given years
     */
    private function generateHolidays(int $startYear, int $endYear): array
    {
        $holidays = [];

        for ($year = $startYear; $year <= $endYear; $year++) {
            // Fixed date holidays
            $holidays[] = "{$year}-01-01"; // New Year's Day
            $holidays[] = "{$year}-01-02"; // Day after New Year
            $holidays[] = "{$year}-02-06"; // Waitangi Day
            $holidays[] = "{$year}-04-25"; // ANZAC Day
            $holidays[] = "{$year}-12-25"; // Christmas Day
            $holidays[] = "{$year}-12-26"; // Boxing Day

            // Auckland Anniversary (Monday closest to Jan 29)
            $jan29 = new \DateTime("{$year}-01-29");
            $dayOfWeek = (int) $jan29->format('N');
            if ($dayOfWeek <= 4) {
                // Mon-Thu: go to previous Monday
                $jan29->modify('monday this week');
            } else {
                // Fri-Sun: go to next Monday
                $jan29->modify('next monday');
            }
            $holidays[] = $jan29->format('Y-m-d');

            // Easter (calculate)
            $easter = $this->calculateEaster($year);
            $holidays[] = (clone $easter)->modify('-2 days')->format('Y-m-d'); // Good Friday
            $holidays[] = (clone $easter)->modify('+1 day')->format('Y-m-d');  // Easter Monday

            // Queen's Birthday (first Monday of June)
            $june1 = new \DateTime("{$year}-06-01");
            if ((int) $june1->format('N') !== 1) {
                $june1->modify('next monday');
            }
            $holidays[] = $june1->format('Y-m-d');

            // Matariki (varies - this is approximate, should be updated yearly)
            // For 2024: June 28, 2025: June 20, 2026: July 10
            $matariki = $this->getMatarikiDate($year);
            if ($matariki) {
                $holidays[] = $matariki;
            }

            // Labour Day (fourth Monday of October)
            $oct1 = new \DateTime("{$year}-10-01");
            if ((int) $oct1->format('N') !== 1) {
                $oct1->modify('next monday');
            }
            $oct1->modify('+3 weeks');
            $holidays[] = $oct1->format('Y-m-d');
        }

        return $holidays;
    }

    /**
     * Calculate Easter Sunday for a given year
     */
    private function calculateEaster(int $year): \DateTime
    {
        $base = new \DateTime("{$year}-03-21");
        $days = easter_days($year);
        return $base->modify("+{$days} days");
    }

    /**
     * Get Matariki date for a given year
     */
    private function getMatarikiDate(int $year): ?string
    {
        // Matariki dates (officially announced)
        $matarikiDates = [
            2024 => '2024-06-28',
            2025 => '2025-06-20',
            2026 => '2026-07-10',
            2027 => '2027-06-25',
            2028 => '2028-07-14',
            2029 => '2029-07-06',
            2030 => '2030-06-21',
        ];

        return $matarikiDates[$year] ?? null;
    }

    /**
     * Check if a date is a public holiday
     */
    private function isPublicHoliday(string $date, array $holidays): bool
    {
        return in_array($date, $holidays, true);
    }

    /**
     * Get day name from day number
     */
    private function getDayName(int $day): string
    {
        $days = [
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
        ];
        return $days[$day] ?? 'Unknown';
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
        return 'Generate training night events';
    }

    /**
     * Get command usage
     */
    public static function getUsage(): string
    {
        return <<<USAGE
Usage: php artisan trainings:generate [options]

Options:
  --months=N        Number of months ahead to generate (default: 12)
  --brigade=ID      Brigade ID (default: 1)
  --dry-run         Show what would be created without making changes

Examples:
  php artisan trainings:generate
  php artisan trainings:generate --months=6 --dry-run
  php artisan trainings:generate --brigade=2 --months=12
USAGE;
    }
}
