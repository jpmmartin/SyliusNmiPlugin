<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// phpunit.xml.dist already forces APP_ENV=test before this file runs. Default it here too, so
// any other runner that reaches this bootstrap lands in the test environment, where the test
// application's Dotenv loads tests/TestApplication/.env.test (and .env.test.local) instead of
// .env.local — Symfony's Dotenv skips .env.local whenever APP_ENV is "test".
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'test';

// Loads vendor/sylius/test-application/.env, then this plugin's tests/TestApplication/.env*,
// and points the cache and log directories at this repository's var/.
require dirname(__DIR__) . '/vendor/sylius/test-application/config/bootstrap.php';
