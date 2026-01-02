<?php
declare(strict_types=1);

/**
 * Test Database Setup Script
 * Run before E2E tests to set up a fresh test database with test data
 */

$testDbPath = __DIR__ . '/../../data/test-portal.db';

// Remove existing test database
if (file_exists($testDbPath)) {
    unlink($testDbPath);
}

// Create new database
$db = new PDO('sqlite:' . $testDbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Load and execute schema
$schema = file_get_contents(__DIR__ . '/../../data/schema.sql');
$db->exec($schema);

// Insert test data
echo "Setting up test database...\n";

// Test Brigade
$db->exec("INSERT INTO brigades (id, name, slug, primary_color, accent_color) VALUES (1, 'Puke Volunteer Fire Brigade', 'puke', '#D32F2F', '#1976D2')");

// Test Members - password hash for '123456'
$pinHash = password_hash('123456', PASSWORD_BCRYPT);

// Super Admin
$db->exec("INSERT INTO members (id, brigade_id, email, name, role, status, pin_hash, access_expires) VALUES (1, 1, 'superadmin@test.com', 'Super Admin', 'superadmin', 'active', '$pinHash', datetime('now', '+5 years'))");

// Admin
$db->exec("INSERT INTO members (id, brigade_id, email, name, role, status, pin_hash, access_expires) VALUES (2, 1, 'admin@test.com', 'Test Admin', 'admin', 'active', '$pinHash', datetime('now', '+5 years'))");

// Officer
$db->exec("INSERT INTO members (id, brigade_id, email, name, role, status, pin_hash, access_expires) VALUES (3, 1, 'officer@test.com', 'Test Officer', 'officer', 'active', '$pinHash', datetime('now', '+5 years'))");

// Firefighters
$db->exec("INSERT INTO members (id, brigade_id, email, name, role, status, pin_hash, access_expires) VALUES (4, 1, 'firefighter1@test.com', 'Firefighter One', 'firefighter', 'active', '$pinHash', datetime('now', '+5 years'))");
$db->exec("INSERT INTO members (id, brigade_id, email, name, role, status, pin_hash, access_expires) VALUES (5, 1, 'firefighter2@test.com', 'Firefighter Two', 'firefighter', 'active', '$pinHash', datetime('now', '+5 years'))");

// Inactive member
$db->exec("INSERT INTO members (id, brigade_id, email, name, role, status, access_expires) VALUES (6, 1, 'inactive@test.com', 'Inactive Member', 'firefighter', 'inactive', datetime('now', '+5 years'))");

// Service periods for Firefighter One
$db->exec("INSERT INTO service_periods (member_id, start_date, notes) VALUES (4, '2020-01-01', 'Initial join')");

// Test Events
$db->exec("INSERT INTO events (id, brigade_id, title, description, start_time, end_time, is_training, created_by) VALUES (1, 1, 'Weekly Training', 'Regular Monday training', datetime('now', '+1 day', 'start of day', '+19 hours'), datetime('now', '+1 day', 'start of day', '+21 hours'), 1, 2)");
$db->exec("INSERT INTO events (id, brigade_id, title, description, start_time, end_time, is_training, created_by) VALUES (2, 1, 'Equipment Check', 'Quarterly equipment inspection', datetime('now', '+7 days', 'start of day', '+10 hours'), datetime('now', '+7 days', 'start of day', '+12 hours'), 0, 2)");
$db->exec("INSERT INTO events (id, brigade_id, title, description, start_time, end_time, is_training, created_by) VALUES (3, 1, 'Past Training', 'Already happened', datetime('now', '-7 days', 'start of day', '+19 hours'), datetime('now', '-7 days', 'start of day', '+21 hours'), 1, 2)");

// Test Notices
$db->exec("INSERT INTO notices (id, brigade_id, title, content, type, author_id) VALUES (1, 1, 'Welcome Notice', 'Welcome to the Puke Fire Portal!', 'standard', 2)");
$db->exec("INSERT INTO notices (id, brigade_id, title, content, type, author_id) VALUES (2, 1, 'Important Announcement', 'This is a sticky notice that stays at top', 'sticky', 2)");
$db->exec("INSERT INTO notices (id, brigade_id, title, content, type, author_id) VALUES (3, 1, 'Urgent Alert', 'This is an urgent notice!', 'urgent', 2)");

// Test Leave Requests
$db->exec("INSERT INTO leave_requests (id, member_id, training_date, reason, status) VALUES (1, 4, date('now', '+7 days'), 'Family event', 'pending')");
$db->exec("INSERT INTO leave_requests (id, member_id, training_date, reason, status, decided_by, decided_at) VALUES (2, 4, date('now', '+14 days'), 'Work commitment', 'approved', 3, datetime('now', '-1 day'))");
$db->exec("INSERT INTO leave_requests (id, member_id, training_date, reason, status, decided_by, decided_at) VALUES (3, 5, date('now', '+7 days'), 'Medical appointment', 'pending')");

// Create magic links for testing (valid for 24 hours)
$testToken = hash('sha256', 'test-magic-link-token');
$db->exec("INSERT INTO magic_links (member_id, token_hash, expires_at) VALUES (4, '$testToken', datetime('now', '+1 day'))");

echo "Test database setup complete!\n";
echo "Test users:\n";
echo "  - superadmin@test.com (PIN: 123456) - Super Admin\n";
echo "  - admin@test.com (PIN: 123456) - Admin\n";
echo "  - officer@test.com (PIN: 123456) - Officer\n";
echo "  - firefighter1@test.com (PIN: 123456) - Firefighter\n";
echo "  - firefighter2@test.com (PIN: 123456) - Firefighter\n";
