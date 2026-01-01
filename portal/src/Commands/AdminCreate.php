<?php
declare(strict_types=1);

/**
 * AdminCreate Command
 *
 * Creates an admin user for the Puke Portal.
 *
 * Usage: php artisan admin:create --email=EMAIL --name=NAME [--role=ROLE] [--brigade=ID]
 */

namespace Commands;

class AdminCreate
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

        // Validate required options
        if (empty($options['email'])) {
            $this->error('Email is required. Use --email=EMAIL');
            return 1;
        }

        if (empty($options['name'])) {
            $this->error('Name is required. Use --name=NAME');
            return 1;
        }

        $email = $options['email'];
        $name = $options['name'];
        $role = $options['role'] ?? 'admin';
        $brigadeId = (int) ($options['brigade'] ?? 1);

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: {$email}");
            return 1;
        }

        // Validate role
        $validRoles = ['firefighter', 'officer', 'admin', 'superadmin'];
        if (!in_array($role, $validRoles, true)) {
            $this->error("Invalid role: {$role}. Valid roles: " . implode(', ', $validRoles));
            return 1;
        }

        // Check if email already exists
        $stmt = $this->db->prepare('SELECT id FROM members WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $this->error("Email already registered: {$email}");
            return 1;
        }

        // Check if brigade exists
        $stmt = $this->db->prepare('SELECT id, name FROM brigades WHERE id = ?');
        $stmt->execute([$brigadeId]);
        $brigade = $stmt->fetch();
        if (!$brigade) {
            $this->error("Brigade not found with ID: {$brigadeId}");
            return 1;
        }

        // Generate access token
        $accessToken = bin2hex(random_bytes(32));
        $accessExpires = date('Y-m-d H:i:s', strtotime('+5 years'));

        try {
            $stmt = $this->db->prepare('
                INSERT INTO members (brigade_id, email, name, role, status, access_token, access_expires)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $brigadeId,
                $email,
                $name,
                $role,
                'active',
                hash('sha256', $accessToken),
                $accessExpires
            ]);

            $memberId = $this->db->lastInsertId();

            $this->success("User created successfully!");
            $this->info("  ID: {$memberId}");
            $this->info("  Name: {$name}");
            $this->info("  Email: {$email}");
            $this->info("  Role: {$role}");
            $this->info("  Brigade: {$brigade['name']}");
            $this->info("");
            $this->info("Magic link (save this!):");

            $appUrl = $this->config['app_url'] ?? 'https://portal.kiaora.tech';
            $this->success("  {$appUrl}/auth/magic?token={$accessToken}");
            $this->info("");
            $this->info("  Link expires: {$accessExpires}");

            return 0;

        } catch (\PDOException $e) {
            $this->error("Database error: {$e->getMessage()}");
            return 1;
        }
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
        return 'Create an admin or member user';
    }

    /**
     * Get command usage
     */
    public static function getUsage(): string
    {
        return <<<USAGE
Usage: php artisan admin:create [options]

Options:
  --email=EMAIL     User email address (required)
  --name=NAME       User full name (required)
  --role=ROLE       User role: firefighter, officer, admin, superadmin (default: admin)
  --brigade=ID      Brigade ID (default: 1)

Examples:
  php artisan admin:create --email=admin@example.com --name="John Smith"
  php artisan admin:create --email=officer@example.com --name="Jane Doe" --role=officer
USAGE;
    }
}
