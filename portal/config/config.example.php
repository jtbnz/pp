<?php
declare(strict_types=1);

/**
 * Puke Portal Configuration
 *
 * Copy this file to config.php and update the values for your environment.
 * NEVER commit config.php to version control.
 */

return [
    // =========================================================================
    // Application Settings
    // =========================================================================

    'app_name' => 'Puke Fire Portal',
    'app_url' => 'https://portal.kiaora.tech',
    'base_path' => '', // Set to '/pp' if running in a subdirectory like https://kiaora.tech/pp/
    'debug' => false, // Set to true for development (shows detailed errors)

    // =========================================================================
    // Database
    // =========================================================================

    'database_path' => __DIR__ . '/../data/portal.db',

    // =========================================================================
    // Timezone
    // =========================================================================

    'timezone' => 'Pacific/Auckland',

    // =========================================================================
    // Session Configuration
    // =========================================================================

    'session' => [
        'timeout' => 63072000,         // 2 years in seconds (for PWA persistence)
        'cookie_secure' => true,       // Set to false for local dev without HTTPS
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',    // Lax recommended for PWA compatibility (Strict can break PWA sessions)
    ],

    // =========================================================================
    // Authentication
    // =========================================================================

    'auth' => [
        'access_duration_years' => 5,     // How long member access lasts
        'invite_expiry_days' => 7,        // Magic link expiry
        'token_reuse_period_seconds' => 300, // Allow magic link reuse within 5 mins (handles email filter prefetching)
        'pin_length' => 6,                // Length of optional PIN
        'pin_attempts' => 5,              // Max failed PIN attempts before lockout
        'lockout_minutes' => 15,          // Lockout duration after failed attempts
        'debug' => false,                 // Enable auth debug logging (logs to data/logs/auth-debug.log)
    ],

    // =========================================================================
    // Rate Limiting
    // =========================================================================

    'rate_limit' => [
        'enabled' => true,
        'max_attempts' => 5,              // Max attempts before lockout
        'lockout_minutes' => 15,          // Lockout duration
        'decay_minutes' => 60,            // Time window for counting attempts
    ],

    // =========================================================================
    // Email Configuration
    // =========================================================================

    'email' => [
        'driver' => 'smtp',               // smtp, sendmail, mail
        'host' => 'smtp.example.com',
        'port' => 587,
        'encryption' => 'tls',            // tls, ssl, or null
        'username' => '',
        'password' => '',
        'from_address' => 'portal@kiaora.tech',
        'from_name' => 'Puke Fire Portal',
        'reply_to' => null,               // Optional reply-to address
    ],

    // =========================================================================
    // Push Notifications (Web Push / VAPID)
    // =========================================================================

    // Generate keys with: npx web-push generate-vapid-keys
    'push' => [
        'enabled' => true,
        'subject' => 'mailto:admin@kiaora.tech',
        'public_key' => '', // VAPID public key (Base64 URL-safe)
        'private_key' => '', // VAPID private key (keep secret!)
        'debug' => false, // Enable push debug logging (logs to data/logs/push-debug.log)
    ],

    // =========================================================================
    // DLB Attendance System Integration
    // =========================================================================

    'dlb' => [
        'enabled' => true,
        'base_url' => 'https://kiaora.tech/dlb/puke',
        'api_token' => '', // Get from DLB admin
        'timeout' => 30, // API request timeout in seconds
    ],

    // =========================================================================
    // Training Night Defaults
    // =========================================================================

    'training' => [
        'default_day' => 1,               // 1 = Monday, 7 = Sunday
        'default_time' => '19:00',        // 24-hour format
        'duration_hours' => 2,            // Default training duration
        'generate_months_ahead' => 12,    // How far ahead to generate trainings
    ],

    // =========================================================================
    // Leave Requests
    // =========================================================================

    'leave' => [
        'max_pending' => 3,               // Max pending/approved requests per member
        'require_reason' => false,        // Whether reason is required
    ],

    // =========================================================================
    // CORS (Cross-Origin Resource Sharing)
    // =========================================================================

    'cors' => [
        'allowed_origins' => ['*'],       // Use specific origins in production
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-CSRF-Token'],
        'max_age' => 86400,               // Preflight cache duration
    ],

    // =========================================================================
    // PWA / Service Worker
    // =========================================================================

    'pwa' => [
        'cache_version' => '1.0.0',       // Increment to force cache refresh
        'offline_page' => '/offline.html',
    ],

    // =========================================================================
    // Logging
    // =========================================================================

    'logging' => [
        'enabled' => true,
        'level' => 'info',                // debug, info, warning, error
        'path' => __DIR__ . '/../data/logs/app.log',
        'max_files' => 30,                // Keep last 30 log files
    ],

    // =========================================================================
    // Security
    // =========================================================================

    'security' => [
        'csrf_enabled' => true,
        'csrf_token_expiry' => 3600,      // 1 hour
        'password_hash_algo' => PASSWORD_BCRYPT,
        'password_hash_options' => [
            'cost' => 12,
        ],
    ],

    // =========================================================================
    // Theming
    // =========================================================================

    'theme' => [
        'primary_color' => '#D32F2F',     // Fire service red
        'accent_color' => '#1976D2',      // Blue accent
        'dark_mode' => 'auto',            // auto, light, dark
    ],

    // =========================================================================
    // Feature Flags
    // =========================================================================

    'features' => [
        'push_notifications' => true,
        'offline_mode' => true,
        'ics_export' => true,
        'extended_leave' => true,         // Admin can add extended leave
        'member_import' => false,         // CSV import of members
    ],
];
