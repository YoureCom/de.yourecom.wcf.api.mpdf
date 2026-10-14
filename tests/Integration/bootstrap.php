<?php

/**
 * Entry point of the integration suite. Loads the local WoltLab Suite installation, the tests use the plugin code
 * installed there (deploy first), not the files of this repository.
 */

$wcfDir = \getenv('WCF_DIR');
$wcfDir = \rtrim(\is_string($wcfDir) && $wcfDir !== '' ? $wcfDir : 'C:/laragon/www/wsc6.2', '/\\');
if (!\is_file($wcfDir . '/global.php')) {
    \fwrite(\STDERR, "No WoltLab Suite installation found in '{$wcfDir}', set WCF_DIR.\n");

    exit(1);
}

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
require $wcfDir . '/global.php';

require_once __DIR__ . '/../support/IntegrationTestCase.php';
