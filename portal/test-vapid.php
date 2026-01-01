<?php
/**
 * VAPID Key Test Script
 *
 * Run this from the command line to verify your VAPID keys are correctly configured.
 * Usage: php test-vapid.php
 *
 * Delete this file after testing.
 */

declare(strict_types=1);

// Load config
$configPath = __DIR__ . '/config/config.php';
if (!file_exists($configPath)) {
    echo "❌ Error: config/config.php not found\n";
    echo "   Copy config/config.example.php to config/config.php first.\n";
    exit(1);
}

$config = require $configPath;

echo "=== VAPID Key Configuration Test ===\n\n";

// Check if push config exists
if (!isset($config['push'])) {
    echo "❌ Error: 'push' configuration section not found in config.php\n";
    exit(1);
}

$push = $config['push'];

// Check enabled flag
echo "1. Push enabled: ";
if ($push['enabled'] ?? false) {
    echo "✅ Yes\n";
} else {
    echo "⚠️  No (set 'enabled' => true to enable)\n";
}

// Check public key
echo "2. Public key: ";
$publicKey = $push['public_key'] ?? '';
if (empty($publicKey)) {
    echo "❌ Missing\n";
} else {
    $length = strlen($publicKey);
    // VAPID public keys are typically 87 characters (65 bytes base64url encoded)
    if ($length >= 80 && $length <= 90) {
        echo "✅ Present ({$length} chars)\n";
    } else {
        echo "⚠️  Present but unusual length ({$length} chars, expected ~87)\n";
    }
}

// Check private key
echo "3. Private key: ";
$privateKey = $push['private_key'] ?? '';
if (empty($privateKey)) {
    echo "❌ Missing\n";
} else {
    $length = strlen($privateKey);
    // VAPID private keys are typically 43 characters (32 bytes base64url encoded)
    if ($length >= 40 && $length <= 50) {
        echo "✅ Present ({$length} chars)\n";
    } else {
        echo "⚠️  Present but unusual length ({$length} chars, expected ~43)\n";
    }
}

// Check subject
echo "4. Subject: ";
$subject = $push['subject'] ?? '';
if (empty($subject)) {
    echo "⚠️  Missing (should be mailto:email or https://url)\n";
} elseif (str_starts_with($subject, 'mailto:') || str_starts_with($subject, 'https://')) {
    echo "✅ {$subject}\n";
} else {
    echo "⚠️  Invalid format: {$subject}\n";
    echo "   Should start with 'mailto:' or 'https://'\n";
}

echo "\n";

// Try to decode keys
echo "5. Key format validation:\n";

function base64UrlDecode(string $data): string|false {
    $padding = 4 - (strlen($data) % 4);
    if ($padding !== 4) {
        $data .= str_repeat('=', $padding);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

$publicDecoded = base64UrlDecode($publicKey);
if ($publicDecoded === false) {
    echo "   Public key: ❌ Invalid base64url encoding\n";
} else {
    $bytes = strlen($publicDecoded);
    echo "   Public key: ✅ Valid base64url ({$bytes} bytes)\n";
    if ($bytes !== 65) {
        echo "   ⚠️  Expected 65 bytes for uncompressed EC public key\n";
    }
}

$privateDecoded = base64UrlDecode($privateKey);
if ($privateDecoded === false) {
    echo "   Private key: ❌ Invalid base64url encoding\n";
} else {
    $bytes = strlen($privateDecoded);
    echo "   Private key: ✅ Valid base64url ({$bytes} bytes)\n";
    if ($bytes !== 32) {
        echo "   ⚠️  Expected 32 bytes for EC private key\n";
    }
}

echo "\n";

// Overall status
$isConfigured = !empty($publicKey) && !empty($privateKey) && ($push['enabled'] ?? false);

if ($isConfigured) {
    echo "=== Overall: ✅ VAPID keys appear to be configured correctly ===\n\n";
    echo "Next steps:\n";
    echo "1. Visit your portal and log in\n";
    echo "2. Go to your profile/settings\n";
    echo "3. Enable push notifications (browser will ask for permission)\n";
    echo "4. Submit a leave request to test if officers receive notifications\n";
} else {
    echo "=== Overall: ❌ VAPID keys need configuration ===\n\n";
    echo "To fix:\n";
    echo "1. Generate VAPID keys (see README.md for instructions)\n";
    echo "2. Add keys to config/config.php in the 'push' section\n";
    echo "3. Set 'enabled' => true\n";
}

echo "\n⚠️  Remember to delete this file after testing!\n";
