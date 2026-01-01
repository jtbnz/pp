#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Puke Portal - Initial Setup Script
 *
 * This script initializes the database, creates the default brigade,
 * and prompts for the first admin user details.
 *
 * Usage: php setup.php
 *
 * Options:
 *   --email=EMAIL     Admin email address
 *   --name=NAME       Admin full name
 *   --non-interactive Skip prompts (requires --email and --name)
 */

// Ensure we're running from CLI
if (PHP_SAPI !== 'cli') {
    die('This script must be run from the command line.');
}

// Set timezone
date_default_timezone_set('Pacific/Auckland');

// Get script directory
$scriptDir = dirname(__FILE__);
$dataDir = $scriptDir . '/data';
$configDir = $scriptDir . '/config';

// Colors for terminal output
class Colors
{
    public const RED = "\033[0;31m";
    public const GREEN = "\033[0;32m";
    public const YELLOW = "\033[1;33m";
    public const BLUE = "\033[0;34m";
    public const NC = "\033[0m"; // No Color
}

/**
 * Print colored output
 */
function output(string $message, string $color = ''): void
{
    echo $color . $message . Colors::NC . PHP_EOL;
}

/**
 * Print a header
 */
function header_line(string $message): void
{
    echo PHP_EOL;
    output(str_repeat('=', 60), Colors::BLUE);
    output($message, Colors::BLUE);
    output(str_repeat('=', 60), Colors::BLUE);
    echo PHP_EOL;
}

/**
 * Get user input from command line
 */
function prompt(string $question, string $default = ''): string
{
    $defaultText = $default ? " [{$default}]" : '';
    echo $question . $defaultText . ': ';
    $input = trim(fgets(STDIN) ?: '');
    return $input !== '' ? $input : $default;
}

/**
 * Parse command line arguments
 */
function parseArgs(array $argv): array
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
 * Validate email address
 */
function validateEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Generate a random access token
 */
function generateToken(): string
{
    return bin2hex(random_bytes(32));
}

// Parse command line arguments
$args = parseArgs($argv);
$nonInteractive = isset($args['non-interactive']);

header_line('Puke Portal - Initial Setup');

// ============================================================================
// Step 1: Check prerequisites
// ============================================================================

output('Checking prerequisites...', Colors::YELLOW);

// Check PHP version
$phpVersion = PHP_VERSION;
if (version_compare($phpVersion, '8.0.0', '<')) {
    output("ERROR: PHP 8.0+ is required. Current version: {$phpVersion}", Colors::RED);
    exit(1);
}
output("  PHP version: {$phpVersion} " . Colors::GREEN . "[OK]" . Colors::NC);

// Check PDO SQLite extension
if (!extension_loaded('pdo_sqlite')) {
    output('ERROR: PDO SQLite extension is not loaded.', Colors::RED);
    exit(1);
}
output('  PDO SQLite extension: ' . Colors::GREEN . '[OK]' . Colors::NC);

// Check if data directory exists and is writable
if (!is_dir($dataDir)) {
    if (!mkdir($dataDir, 0755, true)) {
        output("ERROR: Cannot create data directory: {$dataDir}", Colors::RED);
        exit(1);
    }
}
if (!is_writable($dataDir)) {
    output("ERROR: Data directory is not writable: {$dataDir}", Colors::RED);
    exit(1);
}
output('  Data directory: ' . Colors::GREEN . '[OK]' . Colors::NC);

// Check schema file
$schemaFile = $dataDir . '/schema.sql';
if (!file_exists($schemaFile)) {
    output("ERROR: Schema file not found: {$schemaFile}", Colors::RED);
    exit(1);
}
output('  Schema file: ' . Colors::GREEN . '[OK]' . Colors::NC);

echo PHP_EOL;

// ============================================================================
// Step 2: Create config file if needed
// ============================================================================

output('Checking configuration...', Colors::YELLOW);

$configFile = $configDir . '/config.php';
$configExample = $configDir . '/config.example.php';

if (file_exists($configFile)) {
    output('  Config file already exists: ' . Colors::GREEN . '[OK]' . Colors::NC);
} else {
    if (!file_exists($configExample)) {
        output("ERROR: Config example file not found: {$configExample}", Colors::RED);
        exit(1);
    }

    output('  Creating config.php from config.example.php...', Colors::YELLOW);

    // Read example config
    $configContent = file_get_contents($configExample);

    // Modify for production
    $configContent = preg_replace(
        "/'debug' => true/",
        "'debug' => false",
        $configContent
    );

    // Write config file
    if (file_put_contents($configFile, $configContent) === false) {
        output("ERROR: Cannot write config file: {$configFile}", Colors::RED);
        exit(1);
    }

    output('  Config file created: ' . Colors::GREEN . '[OK]' . Colors::NC);
    output('  ' . Colors::YELLOW . 'IMPORTANT: Edit config.php to set your email and DLB credentials!' . Colors::NC);
}

echo PHP_EOL;

// ============================================================================
// Step 3: Initialize database
// ============================================================================

output('Initializing database...', Colors::YELLOW);

$dbPath = $dataDir . '/portal.db';
$dbExists = file_exists($dbPath);

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Enable foreign keys and WAL mode
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA journal_mode = WAL');

    // Check if tables exist
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);

    if (empty($tables)) {
        output('  Creating database schema...', Colors::YELLOW);

        $schema = file_get_contents($schemaFile);
        $db->exec($schema);

        output('  Database schema created: ' . Colors::GREEN . '[OK]' . Colors::NC);
    } else {
        output('  Database already initialized: ' . Colors::GREEN . '[OK]' . Colors::NC);
        output('  Tables found: ' . implode(', ', $tables));
    }

} catch (PDOException $e) {
    output('ERROR: Database error: ' . $e->getMessage(), Colors::RED);
    exit(1);
}

echo PHP_EOL;

// ============================================================================
// Step 4: Check/create default brigade
// ============================================================================

output('Checking brigade...', Colors::YELLOW);

$stmt = $db->prepare('SELECT * FROM brigades WHERE id = 1');
$stmt->execute();
$brigade = $stmt->fetch();

if ($brigade) {
    output("  Default brigade exists: {$brigade['name']} " . Colors::GREEN . '[OK]' . Colors::NC);
} else {
    output('  Creating default brigade...', Colors::YELLOW);

    $stmt = $db->prepare('INSERT INTO brigades (name, slug) VALUES (?, ?)');
    $stmt->execute(['Puke Volunteer Fire Brigade', 'puke']);

    output('  Default brigade created: ' . Colors::GREEN . '[OK]' . Colors::NC);
}

echo PHP_EOL;

// ============================================================================
// Step 5: Create admin user
// ============================================================================

output('Admin user setup...', Colors::YELLOW);

// Check if admin already exists
$stmt = $db->prepare('SELECT * FROM members WHERE role = ? LIMIT 1');
$stmt->execute(['admin']);
$existingAdmin = $stmt->fetch();

if ($existingAdmin) {
    output("  Admin user already exists: {$existingAdmin['email']}", Colors::GREEN);

    if (!$nonInteractive) {
        $createAnother = prompt('  Create another admin user? (y/n)', 'n');
        if (strtolower($createAnother) !== 'y') {
            echo PHP_EOL;
            goto finish;
        }
    } else {
        echo PHP_EOL;
        goto finish;
    }
}

// Get admin email
if (isset($args['email'])) {
    $adminEmail = $args['email'];
} elseif ($nonInteractive) {
    output('ERROR: --email is required in non-interactive mode', Colors::RED);
    exit(1);
} else {
    $adminEmail = prompt('  Enter admin email address');
}

if (!validateEmail($adminEmail)) {
    output("ERROR: Invalid email address: {$adminEmail}", Colors::RED);
    exit(1);
}

// Check if email already exists
$stmt = $db->prepare('SELECT id FROM members WHERE email = ?');
$stmt->execute([$adminEmail]);
if ($stmt->fetch()) {
    output("ERROR: Email already registered: {$adminEmail}", Colors::RED);
    exit(1);
}

// Get admin name
if (isset($args['name'])) {
    $adminName = $args['name'];
} elseif ($nonInteractive) {
    output('ERROR: --name is required in non-interactive mode', Colors::RED);
    exit(1);
} else {
    $adminName = prompt('  Enter admin full name');
}

if (empty($adminName)) {
    output('ERROR: Name is required', Colors::RED);
    exit(1);
}

// Generate access token
$accessToken = generateToken();
$accessExpires = date('Y-m-d H:i:s', strtotime('+5 years'));

// Create admin user
try {
    $stmt = $db->prepare('
        INSERT INTO members (brigade_id, email, name, role, status, access_token, access_expires)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        1, // Default brigade
        $adminEmail,
        $adminName,
        'admin',
        'active',
        hash('sha256', $accessToken),
        $accessExpires
    ]);

    $adminId = $db->lastInsertId();

    output("  Admin user created: {$adminName} <{$adminEmail}> " . Colors::GREEN . '[OK]' . Colors::NC);
    echo PHP_EOL;

    // Display magic link
    output(str_repeat('-', 60), Colors::YELLOW);
    output('IMPORTANT: Save this magic link to access the portal:', Colors::YELLOW);
    output(str_repeat('-', 60), Colors::YELLOW);
    echo PHP_EOL;

    // Load config to get app URL
    $config = require $configFile;
    $appUrl = $config['app_url'] ?? 'https://portal.kiaora.tech';

    output("  {$appUrl}/auth/magic?token={$accessToken}", Colors::GREEN);
    echo PHP_EOL;
    output('  This link expires: ' . $accessExpires, Colors::YELLOW);
    output(str_repeat('-', 60), Colors::YELLOW);

} catch (PDOException $e) {
    output('ERROR: Failed to create admin user: ' . $e->getMessage(), Colors::RED);
    exit(1);
}

finish:
echo PHP_EOL;

// ============================================================================
// Step 6: Create log directory
// ============================================================================

output('Setting up logging...', Colors::YELLOW);

$logDir = $dataDir . '/logs';
if (!is_dir($logDir)) {
    if (!mkdir($logDir, 0755, true)) {
        output("WARNING: Cannot create log directory: {$logDir}", Colors::YELLOW);
    } else {
        output('  Log directory created: ' . Colors::GREEN . '[OK]' . Colors::NC);
    }
} else {
    output('  Log directory exists: ' . Colors::GREEN . '[OK]' . Colors::NC);
}

echo PHP_EOL;

// ============================================================================
// Finish
// ============================================================================

header_line('Setup Complete!');

output('Next steps:', Colors::YELLOW);
echo PHP_EOL;
output('  1. Edit config/config.php with your settings:', Colors::NC);
output('     - Email configuration (SMTP)', Colors::NC);
output('     - DLB API token', Colors::NC);
output('     - VAPID keys for push notifications', Colors::NC);
echo PHP_EOL;
output('  2. Configure your web server (Apache/Nginx)', Colors::NC);
output('     - Point document root to: ' . $scriptDir . '/public', Colors::NC);
echo PHP_EOL;
output('  3. Set up SSL certificate (Let\'s Encrypt)', Colors::NC);
echo PHP_EOL;
output('  4. Set up cron jobs:', Colors::NC);
output('     - php artisan trainings:generate (daily)', Colors::NC);
output('     - php artisan session:clear (hourly)', Colors::NC);
echo PHP_EOL;
output('  5. Access the portal using the magic link provided above', Colors::NC);
echo PHP_EOL;

output('For detailed instructions, see DEPLOYMENT.md', Colors::BLUE);
echo PHP_EOL;
