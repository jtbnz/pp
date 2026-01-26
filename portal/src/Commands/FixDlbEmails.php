<?php
declare(strict_types=1);

namespace Portal\Commands;

use PDO;

/**
 * Fix DLB Import Emails Command
 *
 * Updates placeholder emails (dlb_import_*) to firstname.lastname@fireandemergency.nz
 */
class FixDlbEmails
{
    private PDO $db;
    private array $config;

    public function __construct(PDO $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public static function getUsage(): string
    {
        return <<<USAGE
Fix DLB Import Emails

Usage: php artisan fix:dlb-emails [options]

Options:
  --dry-run     Show what would be changed without making changes
  --help, -h    Show this help message

Description:
  Updates members with placeholder emails (dlb_import_*@placeholder.local)
  to use firstname.lastname@fireandemergency.nz format based on their name.

Examples:
  php artisan fix:dlb-emails --dry-run
  php artisan fix:dlb-emails

USAGE;
    }

    public function execute(array $args): int
    {
        $dryRun = in_array('--dry-run', $args, true);

        echo "\n";
        echo "Fix DLB Import Emails\n";
        echo "=====================\n\n";

        if ($dryRun) {
            echo "[DRY RUN MODE - No changes will be made]\n\n";
        }

        // Find members with placeholder emails
        $stmt = $this->db->query("
            SELECT id, name, email
            FROM members
            WHERE email LIKE 'dlb_import_%'
            ORDER BY name
        ");
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($members)) {
            echo "No members found with placeholder emails.\n\n";
            return 0;
        }

        echo "Found " . count($members) . " members with placeholder emails:\n\n";

        $updateStmt = $this->db->prepare('UPDATE members SET email = ? WHERE id = ?');
        $updated = 0;
        $errors = [];

        foreach ($members as $member) {
            $newEmail = $this->generateEmail($member['name']);

            echo sprintf(
                "  %s\n    Old: %s\n    New: %s\n\n",
                $member['name'],
                $member['email'],
                $newEmail
            );

            if (!$dryRun) {
                try {
                    $updateStmt->execute([$newEmail, $member['id']]);
                    $updated++;
                } catch (\PDOException $e) {
                    $errors[] = sprintf("%s: %s", $member['name'], $e->getMessage());
                }
            }
        }

        if ($dryRun) {
            echo "Dry run complete. Run without --dry-run to apply changes.\n\n";
        } else {
            echo "Updated {$updated} of " . count($members) . " members.\n";

            if (!empty($errors)) {
                echo "\nErrors:\n";
                foreach ($errors as $error) {
                    echo "  - {$error}\n";
                }
            }
            echo "\n";
        }

        return empty($errors) ? 0 : 1;
    }

    /**
     * Generate email from member name
     *
     * "CFO John Robinson" -> "john.robinson@fireandemergency.nz"
     * "Jane Mary Smith" -> "jane.smith@fireandemergency.nz" (first + last only)
     */
    private function generateEmail(string $name): string
    {
        // Remove common rank prefixes
        $rankPrefixes = [
            'CFO', 'DCFO', 'ACFO', 'SO', 'SSO', 'Stn Off', 'Station Officer',
            'FF', 'QFF', 'SFF', 'Firefighter', 'Qualified Firefighter',
            'Senior Firefighter', 'Chief Fire Officer', 'Deputy Chief',
            'Assistant Chief', 'Recruit', 'Trainee'
        ];

        $cleanName = $name;
        foreach ($rankPrefixes as $prefix) {
            if (stripos($cleanName, $prefix . ' ') === 0) {
                $cleanName = substr($cleanName, strlen($prefix) + 1);
                break;
            }
        }

        // Split into parts and take first and last
        $parts = preg_split('/\s+/', trim($cleanName));

        if (count($parts) >= 2) {
            $firstName = $parts[0];
            $lastName = $parts[count($parts) - 1];
        } else {
            $firstName = $parts[0] ?? 'unknown';
            $lastName = 'member';
        }

        // Clean and lowercase
        $firstName = strtolower(preg_replace('/[^a-zA-Z]/', '', $firstName));
        $lastName = strtolower(preg_replace('/[^a-zA-Z]/', '', $lastName));

        return "{$firstName}.{$lastName}@fireandemergency.nz";
    }
}
