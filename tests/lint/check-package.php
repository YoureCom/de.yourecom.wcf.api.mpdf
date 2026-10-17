<?php

/**
 * YoureCom Standard: checks package.xml and the generated files of the package. Managed by _dev/standard (apply.php),
 * do not edit here.
 *  - every required package is listed once, com.woltlab.wcf is one of them
 *  - com.woltlab.wcf is excluded from the next minor version on (`<excludedpackage version="6.3.0 Alpha 1">` for a
 *    minversion of 6.2.x), the package is not installable on a version it was not tested with
 *  - the delete PIPs (fileDelete.xml, templateDelete.xml, acpTemplateDelete.xml) run in every update
 *  - the bootstrap file is named exactly like the package, character by character: the core only loads
 *    lib/bootstrap/<package>.php (RebuildBootstrapper), on a case-sensitive file system a different casing is never loaded
 *  - fileDelete runs before the files of an update when it deletes an old casing of the bootstrap file: on a
 *    case-insensitive file system the extracted file keeps the old name and would be deleted afterwards
 *  - constants.php matches option.xml (written by apply.php; `--fix` writes it here)
 *
 * Usage: php tests/lint/check-package.php [--fix]
 */

namespace yourecom\standard;

require __DIR__ . '/lib.php';

$fix = \in_array('--fix', $argv, true);
$errors = [];

$package = xml(ROOT . '/package.xml');
if ($package === null) {
    fail(['package.xml is missing or not well-formed']);
}

$required = [];
foreach ($package->query('//w:requiredpackages/w:requiredpackage') ?: [] as $node) {
    if (!$node instanceof \DOMElement) {
        continue;
    }
    $name = \trim($node->textContent);
    if (isset($required[$name])) {
        $errors[] = "package.xml: {$name} is required twice";
    }
    $required[$name] = $node->getAttribute('minversion');
}

if (!isset($required['com.woltlab.wcf'])) {
    $errors[] = 'package.xml: com.woltlab.wcf is not a required package';
} elseif (\preg_match('~^(\d+)\.(\d+)\.~', $required['com.woltlab.wcf'], $version)) {
    $expected = $version[1] . '.' . ((int)$version[2] + 1) . '.0 Alpha 1';
    $excluded = null;
    foreach ($package->query('//w:excludedpackages/w:excludedpackage') ?: [] as $node) {
        if ($node instanceof \DOMElement && \trim($node->textContent) === 'com.woltlab.wcf') {
            $excluded = $node->getAttribute('version');
        }
    }
    if ($excluded !== $expected) {
        $errors[] = "package.xml: <excludedpackage version=\"{$expected}\">com.woltlab.wcf</excludedpackage> is missing"
            . ($excluded !== null ? " (found version=\"{$excluded}\")" : '');
    }
} else {
    $errors[] = "package.xml: com.woltlab.wcf has no minversion";
}

$updates = $package->query('//w:instructions[@type="update"]') ?: [];
foreach (['fileDelete', 'templateDelete', 'acpTemplateDelete'] as $pip) {
    if (!\is_file(ROOT . "/{$pip}.xml")) {
        continue;
    }
    foreach ($updates as $update) {
        if ($update instanceof \DOMElement && $package->query("w:instruction[@type=\"{$pip}\"]", $update)?->length === 0) {
            $errors[] = "package.xml: the update from {$update->getAttribute('fromversion')} has no <instruction type=\"{$pip}\" />, {$pip}.xml is not applied";
        }
    }
}

$packageName = packageName();
foreach (\glob(ROOT . '/files*/lib/bootstrap/*.php') ?: [] as $file) {
    if (\basename($file, '.php') !== $packageName) {
        $relative = \substr($file, \strlen(ROOT) + 1);
        $errors[] = "{$relative}: the core only loads lib/bootstrap/{$packageName}.php, name it exactly like the package";
    }
}

// an old casing of the bootstrap file in fileDelete.xml is the same file on a case-insensitive file system
$deletesOldCasing = false;
foreach (xml(ROOT . '/fileDelete.xml')?->query('//w:file[@application="wcf"]') ?: [] as $node) {
    $name = \basename(\trim($node->textContent), '.php');
    if (\str_starts_with(\trim($node->textContent), 'lib/bootstrap/') && \strcasecmp($name, $packageName) === 0) {
        $deletesOldCasing = true;
    }
}
if ($deletesOldCasing) {
    foreach ($updates as $update) {
        if (!$update instanceof \DOMElement) {
            continue;
        }
        foreach ($package->query('w:instruction', $update) ?: [] as $instruction) {
            if (!$instruction instanceof \DOMElement) {
                continue;
            }
            if ($instruction->getAttribute('type') === 'fileDelete') {
                break;
            }
            if ($instruction->getAttribute('type') === 'file') {
                $errors[] = "package.xml: the update from {$update->getAttribute('fromversion')} extracts the files before <instruction type=\"fileDelete\" />, which deletes an old casing of the bootstrap file: move fileDelete first";
                break;
            }
        }
    }
}

$constants = constantsSource(ROOT);
$current = \is_file(ROOT . '/constants.php') ? (string)\file_get_contents(ROOT . '/constants.php') : null;
if ($current === null || constantDefinitions($current) !== constantDefinitions($constants)) {
    if ($fix) {
        \file_put_contents(ROOT . '/constants.php', $constants);
        echo "constants.php written\n";
    } else {
        $errors[] = 'constants.php does not match option.xml, run: php tests/lint/check-package.php --fix';
    }
}

finish($errors, \count($required) . ' required packages');
