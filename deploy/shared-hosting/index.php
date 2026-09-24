<?php

/*
 * Front controller for cPanel / DirectAdmin hosting.
 *
 * This file lives in the domain's web root (public_html). The application
 * itself lives in an "election-shield-web" folder *outside* the web root, next to
 * public_html (or up to two levels higher), so .env and the code can never be
 * downloaded.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$appPath = null;

foreach ([__DIR__.'/../election-shield-web', __DIR__.'/../../election-shield-web', __DIR__.'/../../../election-shield-web'] as $candidate) {
    if (is_file($candidate.'/bootstrap/app.php')) {
        $appPath = realpath($candidate);
        break;
    }
}

if ($appPath === null) {
    http_response_code(500);
    exit('Election Shield: the "election-shield-web" folder was not found next to this web root.');
}

if (file_exists($maintenance = $appPath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appPath.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $appPath.'/bootstrap/app.php';

$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
