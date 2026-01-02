<?php
declare(strict_types=1);

/**
 * PHPUnit Test Bootstrap
 * Sets up the testing environment
 */

// Set timezone
date_default_timezone_set('Pacific/Auckland');

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Autoload via composer
require_once __DIR__ . '/../vendor/autoload.php';

// Load the bootstrap to get access to classes
require_once __DIR__ . '/../src/bootstrap.php';
