<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../../expenses-back/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../../expenses-back/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../../expenses-back/bootstrap/app.php')
    ->handleRequest(Request::capture());
