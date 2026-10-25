<?php

/**
 * YoureCom Standard: line coverage of the unit and the integration suite together, against the floor in
 * tests/coverage-minimum-combined.txt. Local only (check.sh, after the integration suite): the CI has no dev system, its floor
 * stays the unit only one of tests/coverage-minimum.txt. The integration suite runs the deployed copy of the plugin in the dev
 * system, so its report is mapped back to the files of this repository by the path below the application directory; only files
 * of the repository count, and the statements are those of the unit report. Like the unit floor, the combined floor only goes
 * up (with --raise: measured coverage minus one point, rounded down). Managed by _dev/standard (apply.php), do not edit here.
 *
 * Usage: php tests/lint/check-coverage-combined.php <unit.xml> <integration.xml> [--raise]
 *        php tests/lint/check-coverage-combined.php --app-dir <installation dir>   (directory of the application in the dev system)
 */

namespace yourecom\standard;

require __DIR__ . '/lib.php';

/**
 * Returns the path with forward slashes.
 */
function slashes(string $path): string
{
    return \str_replace('\\', '/', $path);
}

/**
 * Returns the path below the prefix (both with forward slashes), or the path itself without the prefix. The comparison ignores the
 * case on Windows, where the reports can spell the drive letter or a folder differently from the installation setting.
 */
function withoutPrefix(string $path, string $prefix): string
{
    $matches = \PHP_OS_FAMILY === 'Windows' ? \stripos($path, $prefix) === 0 : \str_starts_with($path, $prefix);

    return $matches ? \substr($path, \strlen($prefix)) : $path;
}

/**
 * Returns the application directory of the dev system that holds the deployed `files/lib` of this plugin, or null.
 */
function appDir(string $installation): ?string
{
    $source = \realpath(ROOT . '/files/lib');
    if ($source === false) {
        return null;
    }

    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
    $matches = [];
    foreach ($iterator as $file) {
        if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = \substr(slashes($file->getPathname()), \strlen(slashes($source)) + 1);
        foreach (\glob(\rtrim(slashes($installation), '/') . '/*/lib/' . $relative) ?: [] as $deployed) {
            $dir = \dirname($deployed, \substr_count($relative, '/') + 2);
            $matches[$dir] = ($matches[$dir] ?? 0) + 1;
        }
    }

    // other applications of the installation hold files of the same name: the application with most of the files is the one
    \arsort($matches);

    return \array_key_first($matches);
}

/**
 * Returns the statement lines of a Clover report by file: `[path => [line => covered]]`.
 *
 * @return array<string, array<int, bool>>
 */
function statements(string $report, string $stripPrefix): array
{
    $xml = \simplexml_load_file($report);
    if ($xml === false) {
        fail(["The coverage report {$report} cannot be read"]);
    }

    $files = [];
    foreach ($xml->xpath('//file') ?: [] as $file) {
        $name = slashes((string)$file['name']);
        $name = withoutPrefix($name, $stripPrefix);
        foreach ($file->line as $line) {
            if ((string)$line['type'] === 'stmt') {
                $files[$name][(int)$line['num']] = (int)$line['count'] > 0;
            }
        }
    }

    return $files;
}

if (($argv[1] ?? '') === '--app-dir') {
    $dir = appDir($argv[2] ?? '');
    if ($dir === null) {
        exit(1);
    }

    echo $dir, "\n";

    exit(0);
}

$unitReport = $argv[1] ?? '';
$integrationReport = $argv[2] ?? '';
$raise = \in_array('--raise', $argv, true);
foreach ([$unitReport, $integrationReport] as $report) {
    if (!\is_file($report)) {
        fail(["The coverage report '{$report}' does not exist (run both suites with --coverage-clover first)"]);
    }
}

$root = slashes((string)\realpath(ROOT)) . '/';
$unit = statements($unitReport, $root);
$installation = \getenv('WCF_DIR') ?: 'C:/laragon/www/wsc6.2';
$appDir = appDir($installation);
if ($appDir === null) {
    fail(['The deployed plugin was not found in the dev system, so the integration coverage cannot be mapped']);
}

$integration = statements($integrationReport, slashes((string)(\realpath($appDir) ?: $appDir)) . '/');
$statements = 0;
$covered = 0;
foreach ($unit as $name => $lines) {
    $other = $integration[\preg_replace('~^files/~', '', $name) ?? $name] ?? [];
    foreach ($lines as $number => $isCovered) {
        $statements++;
        if ($isCovered || ($other[$number] ?? false)) {
            $covered++;
        }
    }
}

$percent = $statements > 0 ? 100 * $covered / $statements : 100.0;
$floorFile = ROOT . '/tests/coverage-minimum-combined.txt';
$minimum = (float)\trim((string)@\file_get_contents($floorFile));

\printf("Coverage of unit and integration suite: %.2f%% (%d of %d statements), floor %.2f%%\n", $percent, $covered, $statements, $minimum);
if ($percent + 0.005 < $minimum) {
    fail(['The combined coverage is below the floor.']);
}

$lifted = \floor($percent - 1);
if ($raise && $lifted > $minimum) {
    \file_put_contents($floorFile, \sprintf("%d\n", $lifted));
    \printf("Combined floor raised from %s to %d%% (tests/coverage-minimum-combined.txt), commit it with the change.\n", $minimum, $lifted);
}
