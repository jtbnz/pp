#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Generate Magic Link for Existing User
 *
 * Usage: php generate-magic-link.php --email=user@example.com
 */

if (PHP_SAPI !== 'cli') {
    die('This script must be run from the command line.');
}

// Parse arguments
$args = [];
foreach ($argv as $arg) {
    if (preg_match('/^--([^=]+)=(.*)$/', $arg, $matches)) {
        $args[$matches[1]] = $matches[2];
    }
}

if (!isset($args['email'])) {
    echo "Usage: php generate-magic-link.php --email=user@example.com\n";
    exit(1);
}

$email = $args['email'];

// Load config
$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    echo "ERROR: Config file not found.\n";
    exit(1);
}
$config = require $configFile;

// Connect to database
$dbPath = $config['database_path'] ?? __DIR__ . '/data/portal.db';
try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "ERROR: Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Find the member
$stmt = $db->prepare('SELECT * FROM members WHERE email = ?');
$stmt->execute([$email]);
$member = $stmt->fetch();

if (!$member) {
    echo "ERROR: No member found with email: {$email}\n";
    exit(1);
}

echo "Found member: {$member['name']} ({$member['email']})\n";
echo "Role: {$member['role']}, Status: {$member['status']}\n\n";

// Generate new access token
$token = bin2hex(random_bytes(32));
$hashedToken = hash('sha256', $token);
$expires = date('Y-m-d H:i:s', strtotime('+5 years'));

// Update the member's access token
$stmt = $db->prepare('UPDATE members SET access_token = ?, access_expires = ? WHERE id = ?');
$stmt->execute([$hashedToken, $expires, $member['id']]);

// Build the magic link URL
$basePath = $config['base_path'] ?? '';
$appUrl = rtrim($config['app_url'] ?? 'https://kiaora.tech', '/');

// Construct the full URL
$magicLink = "{$appUrl}{$basePath}/auth/magic?token={$token}";

echo "============================================================\n";
echo "NEW MAGIC LINK GENERATED\n";
echo "============================================================\n\n";
echo "Link: {$magicLink}\n\n";
echo "Expires: {$expires}\n";
echo "============================================================\n";
