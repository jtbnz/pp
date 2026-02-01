<?php
declare(strict_types=1);

/**
 * Migration: Add adjust_for_holidays column to events table
 *
 * This allows recurring events to automatically shift to the next day
 * when an instance falls on a public holiday (e.g., Monday training
 * moves to Tuesday when Monday is Auckland Anniversary Day).
 *
 * Run: php migrations/002_add_adjust_for_holidays.php
 */

// Set timezone
date_default_timezone_set('Pacific/Auckland');

// Determine the base directory
$baseDir = dirname(__DIR__);

// Load database connection
$dbPath = $baseDir . '/data/portal.db';

if (!file_exists($dbPath)) {
    echo "Error: Database not found at {$dbPath}\n";
    exit(1);
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Migration: Add adjust_for_holidays column to events table\n";
echo "===========================================================\n\n";

// Check if column already exists
$stmt = $db->query("PRAGMA table_info(events)");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
$columnNames = array_column($columns, 'name');

if (in_array('adjust_for_holidays', $columnNames)) {
    echo "Column 'adjust_for_holidays' already exists. Skipping.\n";
    exit(0);
}

// Add the column
echo "Adding 'adjust_for_holidays' column...\n";

$db->exec("ALTER TABLE events ADD COLUMN adjust_for_holidays INTEGER DEFAULT 0");

echo "Column added successfully.\n\n";

// Optionally set existing training events to auto-adjust
echo "Would you like to enable holiday adjustment for existing training events? (y/n): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);

if (trim(strtolower($line)) === 'y') {
    $stmt = $db->prepare("UPDATE events SET adjust_for_holidays = 1 WHERE is_training = 1 OR event_type = 'training'");
    $stmt->execute();
    $count = $stmt->rowCount();
    echo "Updated {$count} training events to auto-adjust for holidays.\n";
}

fclose($handle);

echo "\nMigration completed successfully.\n";
