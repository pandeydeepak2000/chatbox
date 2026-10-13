<?php

/**
 * Laravel - cPanel Root Handler
 * Allows running Laravel smoothly when cPanel Document Root is set to the project root
 * without changing to /public.
 */

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var \Illuminate\Foundation\Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';

$app->handleRequest(\Illuminate\Http\Request::capture());
