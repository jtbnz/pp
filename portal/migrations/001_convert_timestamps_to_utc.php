<?php
/**
 * Migration: Convert all timestamps from NZ local time to UTC
 *
 * This migration fixes the timezone issue where dates were stored using
 * datetime('now', 'localtime') which stored in server's local time,
 * but the application now expects UTC.
 *
 * Run this once: php migrations/001_convert_timestamps_to_utc.php
 */

declare(strict_types=1);

// Bootstrap the application
require_once __DIR__ . '/../src/bootstrap.php';

echo "=== Timestamp Migration: Local Time to UTC ===\n\n";

// NZ is UTC+12 in winter (NZST) or UTC+13 in summer (NZDT)
// We'll use PHP's timezone handling to convert correctly

$tables = [
    'audit_log' => ['created_at'],
    'notices' => ['display_from', 'display_to', 'created_at', 'updated_at'],
    'sessions' => ['expires_at', 'created_at'],
    'invite_tokens' => ['expires_at', 'created_at', 'used_at'],
    'members' => ['last_login_at', 'created_at', 'access_expires'],
    'events' => ['start_time', 'end_time', 'created_at', 'updated_at'],
    'leave_requests' => ['requested_at', 'decided_at'],
    'rate_limits' => ['first_attempt_at', 'locked_until'],
    'settings' => ['updated_at'],
];

$nzTz = new DateTimeZone('Pacific/Auckland');
$utcTz = new DateTimeZone('UTC');

$totalUpdated = 0;

foreach ($tables as $table => $columns) {
    echo "Processing table: {$table}\n";

    // Check if table exists
    $tableCheck = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'");
    if (!$tableCheck->fetch()) {
        echo "  - Table does not exist, skipping\n";
        continue;
    }

    foreach ($columns as $column) {
        // Check if column exists
        $pragma = $db->query("PRAGMA table_info({$table})");
        $columnExists = false;
        while ($row = $pragma->fetch()) {
            if ($row['name'] === $column) {
                $columnExists = true;
                break;
            }
        }

        if (!$columnExists) {
            echo "  - Column {$column} does not exist, skipping\n";
            continue;
        }

        // Get all rows with non-null values in this column
        $stmt = $db->prepare("SELECT rowid, {$column} FROM {$table} WHERE {$column} IS NOT NULL");
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $updated = 0;
        foreach ($rows as $row) {
            $oldValue = $row[$column];

            // Skip if already looks like it might be UTC (heuristic: check if it's a recent value)
            // This is a simple migration - we assume all existing data is in NZ time

            try {
                // Parse as NZ local time
                $dt = new DateTime($oldValue, $nzTz);
                // Convert to UTC
                $dt->setTimezone($utcTz);
                $newValue = $dt->format('Y-m-d H:i:s');

                // Update the row
                $updateStmt = $db->prepare("UPDATE {$table} SET {$column} = ? WHERE rowid = ?");
                $updateStmt->execute([$newValue, $row['rowid']]);
                $updated++;
            } catch (Exception $e) {
                echo "  - Error converting {$column} value '{$oldValue}': {$e->getMessage()}\n";
            }
        }

        if ($updated > 0) {
            echo "  - Updated {$updated} rows in column {$column}\n";
            $totalUpdated += $updated;
        }
    }
}

echo "\n=== Migration Complete ===\n";
echo "Total rows updated: {$totalUpdated}\n";
echo "\nNote: All timestamps are now stored in UTC.\n";
echo "The application will convert to NZ time for display.\n";
