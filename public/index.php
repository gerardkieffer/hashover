<?php

declare(strict_types=1);

use HashOver\Application;
use HashOver\Config;
use HashOver\Http\Request;
use HashOver\Http\Response;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $response = Application::fromConfigFile(Config::defaultFile())->handle(Request::fromGlobals());
} catch (Throwable $error) {
    error_log('HashOver: ' . $error);
    $response = Response::text('HashOver is unavailable; see the server error log.', 500);
}

$response->send();
