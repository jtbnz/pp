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

// Autoloader for application classes
spl_autoload_register(function (string $class): void {
    // Convert namespace to path
    // Classes are in src/ directory without namespace prefix
    $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';

    if (file_exists($file)) {
        require $file;
        return;
    }

    // Try subdirectories: Controllers, Models, Services, Middleware, Helpers
    $directories = ['Controllers', 'Models', 'Services', 'Middleware', 'Helpers'];
    foreach ($directories as $dir) {
        $file = __DIR__ . '/' . $dir . '/' . $class . '.php';
        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});

// Load configuration
$configFile = __DIR__ . '/../config/config.php';
if (!file_exists($configFile)) {
    // Fall back to example config for initial setup
    $configFile = __DIR__ . '/../config/config.example.php';
    if (!file_exists($configFile)) {
        throw new RuntimeException('Configuration file not found. Copy config.example.php to config.php');
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

// Session configuration (only for web requests, not CLI)
if (PHP_SAPI !== 'cli') {
    $sessionConfig = $config['session'] ?? [];

    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $sessionConfig['cookie_secure'] ?? '1');
    ini_set('session.cookie_samesite', $sessionConfig['cookie_samesite'] ?? 'Strict');
    ini_set('session.gc_maxlifetime', (string)($sessionConfig['timeout'] ?? 86400));

    // Use custom session name
    session_name('puke_portal_session');

    // Start session only if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Regenerate session ID periodically for security
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } elseif (time() - $_SESSION['created'] > 1800) {
        // Regenerate session ID every 30 minutes
        session_regenerate_id(true);
        $_SESSION['created'] = time();
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

// Helper function to check if user has role
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

// Helper function for relative time formatting
function timeAgo(string $datetime): string
{
    $time = strtotime($datetime);
    $diff = time() - $time;

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
        return date('j M Y', $time);
    }
}

// Include the Router class
require_once __DIR__ . '/Router.php';
