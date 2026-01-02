<?php
/**
 * Test Router - Simulates /pp subdirectory deployment
 *
 * This router is used by Playwright tests to simulate the production
 * deployment where the app is served from /pp subdirectory.
 *
 * For testing convenience, requests without /pp prefix are also handled
 * and internally treated as if they had the /pp prefix.
 *
 * Usage: APP_ENV=testing php -S localhost:8080 tests/router.php
 */

$uri = $_SERVER['REQUEST_URI'];
$basePath = '/pp';

// Check if request starts with /pp
if (strpos($uri, $basePath) === 0) {
    // Remove /pp prefix for internal routing
    $uri = substr($uri, strlen($basePath));
    if ($uri === '' || $uri === false) {
        $uri = '/';
    }
} else {
    // Request doesn't start with /pp - treat as if it did
    // This allows tests to use paths like '/' instead of '/pp/'
    // The internal routing is the same, just without stripping prefix
}

$_SERVER['REQUEST_URI'] = $uri;

// Also update SCRIPT_NAME and PHP_SELF
$_SERVER['SCRIPT_NAME'] = $basePath . '/index.php';

// Parse URI to get path without query string
$uriPath = parse_url($uri, PHP_URL_PATH);
$publicFilePath = __DIR__ . '/../public' . $uriPath;

// If it's a static file, serve it directly
if ($uriPath !== '/' && file_exists($publicFilePath) && is_file($publicFilePath)) {
    // Get mime type
    $ext = pathinfo($publicFilePath, PATHINFO_EXTENSION);
    $mimeTypes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'webmanifest' => 'application/manifest+json',
    ];

    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
    }

    readfile($publicFilePath);
    return true;
}

// Route through index.php
require __DIR__ . '/../public/index.php';
return true;
