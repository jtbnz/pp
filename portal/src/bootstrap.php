<?php
declare(strict_types=1);

/**
 * Puke Portal - Bootstrap
 *
 * Initializes the application: error handling, autoloading, configuration,
 * database connection, and session management.
 */

// Set timezone to Pacific/Auckland for all operations
date_default_timezone_set('Pacific/Auckland');

// Error reporting configuration
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../data/logs/error.log');

// Ensure log directory exists
$logDir = __DIR__ . '/../data/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Custom error handler
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    $message = sprintf(
        "[%s] Error %d: %s in %s on line %d\n",
        date('Y-m-d H:i:s'),
        $errno,
        $errstr,
        $errfile,
        $errline
    );
    error_log($message);

    // Don't execute PHP internal error handler
    return true;
});

// Custom exception handler
set_exception_handler(function (Throwable $e): void {
    $message = sprintf(
        "[%s] Uncaught %s: %s in %s on line %d\nStack trace:\n%s\n",
        date('Y-m-d H:i:s'),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    error_log($message);

    // Send appropriate response
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }

    global $config;
    if (isset($config) && ($config['debug'] ?? false)) {
        echo json_encode([
            'error' => 'Internal Server Error',
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    } else {
        echo json_encode(['error' => 'Internal Server Error']);
    }
    exit(1);
});

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Create class aliases for backward compatibility in templates
class_alias(Portal\Models\Event::class, 'Event');
class_alias(Portal\Models\Notice::class, 'Notice');
class_alias(Portal\Models\LeaveRequest::class, 'LeaveRequest');
class_alias(Portal\Models\Poll::class, 'Poll');
class_alias(Portal\Models\Member::class, 'Member');
class_alias(Portal\Models\Brigade::class, 'Brigade');
class_alias(Portal\Models\MagicLink::class, 'MagicLink');
class_alias(Portal\Models\RateLimit::class, 'RateLimit');
class_alias(Portal\Models\Notification::class, 'Notification');
class_alias(Portal\Services\PushService::class, 'PushService');
class_alias(Portal\Services\DlbService::class, 'DlbService');
class_alias(Portal\Services\CalendarService::class, 'CalendarService');
class_alias(Portal\Services\EmailService::class, 'EmailService');
class_alias(Portal\Services\NotificationService::class, 'NotificationService');
class_alias(Portal\Helpers\Markdown::class, 'Markdown');

// Load configuration based on APP_ENV environment variable
$appEnv = getenv('APP_ENV') ?: 'production';
if ($appEnv === 'testing' && file_exists(__DIR__ . '/../config/config.testing.php')) {
    $configFile = __DIR__ . '/../config/config.testing.php';
} else {
    $configFile = __DIR__ . '/../config/config.php';
    if (!file_exists($configFile)) {
        // Fall back to example config for initial setup
        $configFile = __DIR__ . '/../config/config.example.php';
        if (!file_exists($configFile)) {
            throw new RuntimeException('Configuration file not found. Copy config.example.php to config.php');
        }
    }
}

$config = require $configFile;

// Validate required configuration
$requiredConfig = ['database_path', 'app_url', 'app_name'];
foreach ($requiredConfig as $key) {
    if (!isset($config[$key])) {
        throw new RuntimeException("Missing required configuration: {$key}");
    }
}

// Initialize database connection
$dbPath = $config['database_path'];
$dbDir = dirname($dbPath);

// Ensure database directory exists
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    // Enable foreign keys
    $db->exec('PRAGMA foreign_keys = ON');

    // Enable WAL mode for better concurrent access
    $db->exec('PRAGMA journal_mode = WAL');
} catch (PDOException $e) {
    throw new RuntimeException('Database connection failed: ' . $e->getMessage());
}

// Initialize schema if database is empty
$tablesExist = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='brigades'")->fetch();
if (!$tablesExist) {
    $schemaFile = __DIR__ . '/../data/schema.sql';
    if (file_exists($schemaFile)) {
        $schema = file_get_contents($schemaFile);
        $db->exec($schema);
    }
}

// Schema migrations for existing databases
// Ensure extended_leave_requests table exists (added in Issue #12)
$extendedLeaveExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='extended_leave_requests'")->fetch();
if (!$extendedLeaveExists) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS extended_leave_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            reason TEXT,
            trainings_affected INTEGER NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            decided_by INTEGER,
            decided_at DATETIME,
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
            FOREIGN KEY (decided_by) REFERENCES members(id) ON DELETE SET NULL,
            CHECK (status IN ('pending', 'approved', 'denied')),
            CHECK (end_date >= start_date)
        )
    ");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_extended_leave_member ON extended_leave_requests(member_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_extended_leave_dates ON extended_leave_requests(start_date, end_date)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_extended_leave_status ON extended_leave_requests(status)');
}

// Ensure attendance_records table exists (added in Issue #16)
$attendanceRecordsExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='attendance_records'")->fetch();
if (!$attendanceRecordsExists) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS attendance_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            dlb_muster_id INTEGER NOT NULL,
            event_date DATE NOT NULL,
            event_type VARCHAR(20) NOT NULL,
            status CHAR(1) NOT NULL,
            position VARCHAR(20),
            truck VARCHAR(50),
            notes TEXT,
            source VARCHAR(20) DEFAULT 'dlb',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
            UNIQUE(member_id, dlb_muster_id)
        )
    ");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_attendance_member ON attendance_records(member_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_attendance_date ON attendance_records(event_date)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_attendance_type ON attendance_records(event_type)');
}

// Ensure attendance_sync table exists (added in Issue #16)
$attendanceSyncExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='attendance_sync'")->fetch();
if (!$attendanceSyncExists) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS attendance_sync (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            brigade_id INTEGER NOT NULL,
            last_sync_at DATETIME,
            sync_from_date DATE,
            sync_to_date DATE,
            status VARCHAR(20) DEFAULT 'pending',
            error_message TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (brigade_id) REFERENCES brigades(id) ON DELETE CASCADE
        )
    ");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sync_brigade ON attendance_sync(brigade_id)');
}

// Ensure event_type column exists on events table (added in Issue #7)
try {
    $result = $db->query("SELECT event_type FROM events LIMIT 1");
} catch (PDOException $e) {
    // Column doesn't exist, add it
    $db->exec("ALTER TABLE events ADD COLUMN event_type VARCHAR(20) DEFAULT 'other'");
    // Update existing training events
    $db->exec("UPDATE events SET event_type = 'training' WHERE is_training = 1");
}

// Ensure preferences column exists on members table (added in Issue #23 - color blindness mode)
try {
    $result = $db->query("SELECT preferences FROM members LIMIT 1");
} catch (PDOException $e) {
    // Column doesn't exist, add it (JSON text for storing user preferences)
    $db->exec("ALTER TABLE members ADD COLUMN preferences TEXT DEFAULT '{}'");
}

// Session configuration (only for web requests, not CLI)
if (PHP_SAPI !== 'cli') {
    $sessionConfig = $config['session'] ?? [];
    $sessionTimeout = $sessionConfig['timeout'] ?? 86400;

    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $sessionConfig['cookie_secure'] ?? '1');
    ini_set('session.cookie_samesite', $sessionConfig['cookie_samesite'] ?? 'Lax'); // Changed from Strict to Lax for PWA compatibility
    ini_set('session.gc_maxlifetime', (string)$sessionTimeout);

    // Set cookie lifetime to match session timeout (important for PWA persistence)
    ini_set('session.cookie_lifetime', (string)$sessionTimeout);

    // Use custom session name
    session_name('puke_portal_session');

    // Set session cookie params explicitly for better PWA support
    session_set_cookie_params([
        'lifetime' => $sessionTimeout,
        'path' => '/',
        'domain' => '',
        'secure' => (bool)($sessionConfig['cookie_secure'] ?? true),
        'httponly' => true,
        'samesite' => $sessionConfig['cookie_samesite'] ?? 'Lax',
    ]);

    // Start session only if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Regenerate session ID periodically for security (but less aggressively)
    // On PWAs, too-frequent regeneration can cause issues
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } elseif (time() - $_SESSION['created'] > 3600) {
        // Regenerate session ID every 60 minutes (was 30 - more lenient for PWA)
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }

    // Auto-login from remember token if session has no member_id
    // This handles the Safari → PWA cookie jar isolation issue on iOS
    if (!isset($_SESSION['member_id']) && isset($_COOKIE['puke_remember'])) {
        $rememberToken = $_COOKIE['puke_remember'];
        $tokenHash = hash('sha256', $rememberToken);

        // Ensure remember_tokens table exists (for existing installations)
        $db->exec('
            CREATE TABLE IF NOT EXISTS remember_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                member_id INTEGER NOT NULL,
                token_hash VARCHAR(255) NOT NULL UNIQUE,
                device_name VARCHAR(100),
                user_agent TEXT,
                last_used_at DATETIME,
                expires_at DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
            )
        ');

        $stmt = $db->prepare('
            SELECT rt.*, m.status as member_status
            FROM remember_tokens rt
            JOIN members m ON m.id = rt.member_id
            WHERE rt.token_hash = ?
              AND rt.expires_at > datetime("now")
              AND m.status = "active"
        ');
        $stmt->execute([$tokenHash]);
        $tokenRecord = $stmt->fetch();

        if ($tokenRecord) {
            // Valid remember token - restore session
            $_SESSION['member_id'] = $tokenRecord['member_id'];
            $_SESSION['last_activity'] = time();
            $_SESSION['created'] = time();
            $_SESSION['restored_from_remember_token'] = true;

            // Update last used
            $stmt = $db->prepare('UPDATE remember_tokens SET last_used_at = datetime("now") WHERE id = ?');
            $stmt->execute([$tokenRecord['id']]);

            // Load additional member info
            $stmt = $db->prepare('SELECT brigade_id, name, role, email FROM members WHERE id = ?');
            $stmt->execute([$tokenRecord['member_id']]);
            $member = $stmt->fetch();
            if ($member) {
                $_SESSION['brigade_id'] = $member['brigade_id'];
                $_SESSION['member_name'] = $member['name'];
                $_SESSION['member_role'] = $member['role'];
                $_SESSION['member_email'] = $member['email'];
            }

            // Log the auto-login
            if ($config['auth']['debug'] ?? false) {
                $debugFile = __DIR__ . '/../data/logs/auth-debug.log';
                $logEntry = "[" . date('Y-m-d H:i:s') . "] remember_token_login: " . json_encode([
                    'member_id' => $tokenRecord['member_id'],
                    'token_id' => $tokenRecord['id'],
                    'device_name' => $tokenRecord['device_name'],
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                ], JSON_UNESCAPED_SLASHES) . "\n";
                file_put_contents($debugFile, $logEntry, FILE_APPEND | LOCK_EX);
            }
        } else {
            // Invalid/expired remember token - clear it
            setcookie('puke_remember', '', time() - 3600, '/', '', true, true);
        }
    }

    // Log session debug info if auth debug is enabled
    if ($config['auth']['debug'] ?? false) {
        $debugFile = __DIR__ . '/../data/logs/auth-debug.log';
        if (!isset($_SESSION['session_debug_logged']) || (time() - ($_SESSION['session_debug_logged'] ?? 0)) > 300) {
            $sessionDebug = [
                'session_id_prefix' => substr(session_id(), 0, 16) . '...',
                'member_id' => $_SESSION['member_id'] ?? null,
                'restored_from_token' => $_SESSION['restored_from_remember_token'] ?? false,
                'session_created' => isset($_SESSION['created']) ? date('Y-m-d H:i:s', $_SESSION['created']) : null,
                'last_activity' => isset($_SESSION['last_activity']) ? date('Y-m-d H:i:s', $_SESSION['last_activity']) : null,
                'has_remember_cookie' => isset($_COOKIE['puke_remember']),
                'cookie_params' => session_get_cookie_params(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'is_pwa' => isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
                           (isset($_SERVER['HTTP_SEC_FETCH_MODE']) && $_SERVER['HTTP_SEC_FETCH_MODE'] === 'navigate'),
            ];
            $logEntry = "[" . date('Y-m-d H:i:s') . "] session_check: " . json_encode($sessionDebug, JSON_UNESCAPED_SLASHES) . "\n";
            file_put_contents($debugFile, $logEntry, FILE_APPEND | LOCK_EX);
            $_SESSION['session_debug_logged'] = time();
        }
    }
}

// Helper function to render templates
function render(string $template, array $data = []): void
{
    global $config;

    // Extract data to make variables available in template
    extract($data);

    // Default template variables
    $appName = $config['app_name'] ?? 'Puke Portal';
    $appUrl = $config['app_url'] ?? '';

    // Determine template path
    $templatePath = __DIR__ . '/../templates/' . $template . '.php';

    if (!file_exists($templatePath)) {
        throw new RuntimeException("Template not found: {$template}");
    }

    require $templatePath;
}

// Helper function to return JSON response
function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Helper function to get current authenticated user
function currentUser(): ?array
{
    global $db;

    if (!isset($_SESSION['member_id'])) {
        return null;
    }

    static $user = null;

    if ($user === null) {
        $stmt = $db->prepare('SELECT * FROM members WHERE id = ? AND status = ?');
        $stmt->execute([$_SESSION['member_id'], 'active']);
        $user = $stmt->fetch() ?: null;
    }

    return $user;
}

// Helper function to get the actual user (ignores view-as mode)
function actualUser(): ?array
{
    return currentUser();
}

// Helper function to check if currently in view-as mode
function isViewingAs(): bool
{
    if (!isset($_SESSION['view_as_role']) || !isset($_SESSION['view_as_expires'])) {
        return false;
    }

    // Check if view-as session has expired (30 minutes)
    if (time() > $_SESSION['view_as_expires']) {
        clearViewAs();
        return false;
    }

    return true;
}

// Helper function to get the role being viewed as
function getViewAsRole(): ?string
{
    if (!isViewingAs()) {
        return null;
    }
    return $_SESSION['view_as_role'];
}

// Helper function to get view-as expiration time
function getViewAsExpires(): ?int
{
    if (!isViewingAs()) {
        return null;
    }
    return $_SESSION['view_as_expires'];
}

// Helper function to start view-as session
function startViewAs(string $role): bool
{
    $user = currentUser();
    if (!$user || $user['role'] !== 'superadmin') {
        return false;
    }

    $allowedRoles = ['firefighter', 'officer'];
    if (!in_array($role, $allowedRoles, true)) {
        return false;
    }

    $_SESSION['view_as_role'] = $role;
    $_SESSION['view_as_expires'] = time() + (30 * 60); // 30 minutes

    return true;
}

// Helper function to clear view-as session
function clearViewAs(): void
{
    unset($_SESSION['view_as_role']);
    unset($_SESSION['view_as_expires']);
}

// Helper function to check if user has role (respects view-as mode)
function hasRole(string $role): bool
{
    $user = currentUser();
    if (!$user) {
        return false;
    }

    $roleHierarchy = [
        'firefighter' => 1,
        'officer' => 2,
        'admin' => 3,
        'superadmin' => 4
    ];

    // If in view-as mode, use the impersonated role for permission checks
    if (isViewingAs()) {
        $effectiveRole = getViewAsRole();
    } else {
        $effectiveRole = $user['role'];
    }

    $userLevel = $roleHierarchy[$effectiveRole] ?? 0;
    $requiredLevel = $roleHierarchy[$role] ?? 999;

    return $userLevel >= $requiredLevel;
}

// Helper function to check user's actual role (ignores view-as mode)
function hasActualRole(string $role): bool
{
    $user = currentUser();
    if (!$user) {
        return false;
    }

    $roleHierarchy = [
        'firefighter' => 1,
        'officer' => 2,
        'admin' => 3,
        'superadmin' => 4
    ];

    $userLevel = $roleHierarchy[$user['role']] ?? 0;
    $requiredLevel = $roleHierarchy[$role] ?? 999;

    return $userLevel >= $requiredLevel;
}

// Helper function for CSRF token generation/validation
function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Helper function to sanitize output
function e(string $string): string
{
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Helper function to generate URLs with base path prefix
function url(string $path = ''): string
{
    global $config;
    $basePath = rtrim($config['base_path'] ?? '', '/');

    // Ensure path starts with /
    if ($path !== '' && $path[0] !== '/') {
        $path = '/' . $path;
    }

    return $basePath . $path;
}

// Helper function to generate asset URLs
function asset(string $path): string
{
    return url('/assets/' . ltrim($path, '/'));
}

// Helper function for relative time formatting
// Assumes input datetime is stored in UTC
function timeAgo(string $datetime): string
{
    // Parse the stored UTC time explicitly
    $utcTz = new DateTimeZone('UTC');
    $dt = new DateTime($datetime, $utcTz);
    $timeUtc = $dt->getTimestamp();

    // Get current UTC timestamp
    $nowUtc = time();

    $diff = $nowUtc - $timeUtc;

    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = (int)floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = (int)floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = (int)floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        // Convert to local time for display
        return fromUtc($datetime, 'j M Y');
    }
}

/**
 * Convert a local datetime string to UTC for database storage.
 * Input is assumed to be in Pacific/Auckland timezone.
 *
 * @param string|null $localDatetime Local datetime string (Y-m-d H:i:s or similar)
 * @return string|null UTC datetime string (Y-m-d H:i:s)
 */
function toUtc(?string $localDatetime): ?string
{
    if (empty($localDatetime)) {
        return null;
    }

    $tz = new DateTimeZone('Pacific/Auckland');
    $utcTz = new DateTimeZone('UTC');

    $dt = new DateTime($localDatetime, $tz);
    $dt->setTimezone($utcTz);

    return $dt->format('Y-m-d H:i:s');
}

/**
 * Convert a UTC datetime string from database to local time for display.
 *
 * @param string|null $utcDatetime UTC datetime string
 * @param string $format Output format (default: Y-m-d H:i:s)
 * @return string|null Local datetime string
 */
function fromUtc(?string $utcDatetime, string $format = 'Y-m-d H:i:s'): ?string
{
    if (empty($utcDatetime)) {
        return null;
    }

    $utcTz = new DateTimeZone('UTC');
    $localTz = new DateTimeZone('Pacific/Auckland');

    $dt = new DateTime($utcDatetime, $utcTz);
    $dt->setTimezone($localTz);

    return $dt->format($format);
}

/**
 * Get current UTC datetime for database storage.
 *
 * @return string UTC datetime string (Y-m-d H:i:s)
 */
function nowUtc(): string
{
    return gmdate('Y-m-d H:i:s');
}

// Router is autoloaded via Composer PSR-4
