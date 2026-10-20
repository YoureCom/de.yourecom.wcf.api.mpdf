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
 *  - Beta rule: no installable file changed since the commit that set the current <version>, unless the version is raised
 *    in the pending change
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

// Beta rule (../CLAUDE.md, Versionen): every change of the installable package raises <version> in the same commit. Installable
// files that differ from the commit which set the current version mean a change without its own version.
$versionError = unbumpedChange();
if ($versionError !== null) {
    $errors[] = $versionError;
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

/**
 * Names the installable files changed since the commit that set the current <version>, or null. Outside a git repository, in
 * a shallow clone and while the version differs from HEAD (raised in the pending change) there is nothing to say.
 */
function unbumpedChange(): ?string
{
    $git = static function (string ...$arguments): ?string {
        $command = 'git -C ' . \escapeshellarg(ROOT) . ' ' . \implode(' ', \array_map('escapeshellarg', $arguments)) . ' 2>' . (\PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        \exec($command, $output, $code);

        return $code === 0 ? \implode("\n", $output) : null;
    };
    $versionOf = static fn (?string $xml): ?string => $xml !== null && \preg_match('~<version>([^<]+)</version>~', $xml, $m) ? \trim($m[1]) : null;

    $current = $versionOf((string)@\file_get_contents(ROOT . '/package.xml'));
    if ($current === null || $git('rev-parse', '--is-shallow-repository') !== 'false' || $current !== $versionOf($git('show', 'HEAD:package.xml'))) {
        return null;
    }
    // --format=%H would lose its % to escapeshellarg() on Windows: the hash is the first word of --pretty=oneline
    $base = \strtok((string)$git('log', '-1', '--pretty=oneline', '--no-decorate', '-G<version>', '--', 'package.xml'), ' ');
    if ($base === false || $base === '') {
        return null;
    }
    // compared with the working tree: committed and pending changes alike; tests, docs and dev files are not installed
    $changed = \array_filter(\explode("\n", (string)$git('diff', '--name-only', $base, '--', 'files', 'files_wcf', 'templates', 'acptemplates', 'language', ':(glob)*.xml', ':(exclude)package.xml', ':(exclude)phpunit*.xml')));
    $untracked = \array_filter(\explode("\n", (string)$git('ls-files', '--others', '--exclude-standard', '--', 'files', 'files_wcf', 'templates', 'acptemplates', 'language')));
    $changed = \array_values(\array_unique([...$changed, ...$untracked]));
    if ($changed === []) {
        return null;
    }

    return "package.xml: version {$current} was set in " . \substr($base, 0, 7) . ', since then ' . \count($changed) . ' installable file(s) changed ('
        . \implode(', ', \array_slice($changed, 0, 3)) . (\count($changed) > 3 ? ', ...' : '') . '): raise <version> (next Beta) and <date> with the change';
}
