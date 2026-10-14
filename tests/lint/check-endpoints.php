<?php

/**
 * YoureCom Standard: every RPC endpoint (class with a request attribute below `system/endpoint/controller`) is
 * registered in the bootstrap file, the core does not discover controllers on its own.
 * Managed by _dev/standard (apply.php), do not edit here.
 *
 * Usage: php tests/lint/check-endpoints.php
 */

namespace yourecom\standard;

require __DIR__ . '/lib.php';

$bootstrap = bootstrap();
$errors = [];
$count = 0;

foreach (files(['files', 'files_wcf'], ['.class.php']) as $relative => $path) {
    if (!\str_contains($relative, '/system/endpoint/controller/')) {
        continue;
    }

    $source = (string)\file_get_contents($path);
    if (!\preg_match('~#\[(?:Get|Post|Put|Patch|Delete)Request\(~', $source)
        || !\preg_match('~^namespace ([^;]+);~m', $source, $namespace)
        || !\preg_match('~^(?:final )?class (\w+)~m', $source, $class)) {
        continue;
    }

    $count++;
    $fqcn = $namespace[1] . '\\' . $class[1];
    $short = $class[1] . '()';
    if (!\str_contains($bootstrap, $fqcn) && !\str_contains($bootstrap, 'new ' . $short)) {
        $errors[] = "endpoint not registered in the bootstrap: {$fqcn}";
    }
}

finish($errors, "{$count} endpoints");
