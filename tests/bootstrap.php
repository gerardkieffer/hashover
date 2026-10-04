<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// The procedural helpers rely on these configuration globals
$GLOBALS['domain'] = 'example.com';
$GLOBALS['encryption_key'] = 'unit-test-key-0123456789';
$GLOBALS['admin_nickname'] = 'Boss';
$GLOBALS['admin_password'] = 'adminpw';

require dirname(__DIR__) . '/scripts/functions.php';
require dirname(__DIR__) . '/scripts/encryption.php';
