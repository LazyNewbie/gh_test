<?php

// This file bootstraps the test environment.
error_reporting(E_ALL | E_STRICT);


putenv('THIS_IS_A_TEST_RUN=true');
putenv('EXADS_NETWORK_HASH=testtest');
putenv('EXADS_DOMAIN=test.com');

if (file_exists(__DIR__ . '/../../../vendor/autoload.php')) {
    // dependencies were installed via composer - this is the main project
    $classLoader = require __DIR__ . '/../../../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../../../../autoload.php')) {
    // installed as a dependency in `vendor`
    $classLoader = require __DIR__ . '/../../../../../autoload.php';
} else {
    throw new Exception('Can\'t find autoload.php. Did you install dependencies via Composer?');
}

// @var $classLoader \Composer\Autoload\ClassLoader
$classLoader->add('Exads\\Test\\', __DIR__ . '/../../');
unset($classLoader);
