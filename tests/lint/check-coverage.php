<?php

/**
 * YoureCom Standard: line coverage of the PHPUnit unit suite (Clover report) against the floor in
 * tests/coverage-minimum.txt. The floor only goes up: with --raise (check.sh does that, the CI does not) it is lifted to
 * the measured coverage minus one point, rounded down to a whole number (the margin absorbs the small difference between
 * the local run and the CI). Managed by _dev/standard (apply.php), do not edit here.
 *
 * Usage: php tests/lint/check-coverage.php var/coverage.xml [--raise]
 */

namespace yourecom\standard;

require __DIR__ . '/lib.php';

$report = $argv[1] ?? 'var/coverage.xml';
$raise = \in_array('--raise', $argv, true);
if (!\is_file($report)) {
    fail(["The coverage report {$report} does not exist (run PHPUnit with --coverage-clover first)"]);
}

$xml = \simplexml_load_file($report);
if ($xml === false || !isset($xml->project->metrics)) {
    fail(['The coverage report cannot be read']);
}

$statements = (int)$xml->project->metrics['statements'];
$covered = (int)$xml->project->metrics['coveredstatements'];
$percent = $statements > 0 ? 100 * $covered / $statements : 100.0;
$floorFile = ROOT . '/tests/coverage-minimum.txt';
$minimum = (float)\trim((string)@\file_get_contents($floorFile));

\printf("Coverage of the unit suite: %.2f%% (%d of %d statements), floor %.2f%%\n", $percent, $covered, $statements, $minimum);
if ($percent + 0.005 < $minimum) {
    fail(['The coverage is below the floor.']);
}

// the floor follows the coverage up (never down): check.sh lifts it, the change is committed with the code
$lifted = \floor($percent - 1);
if ($raise && $lifted > $minimum) {
    \file_put_contents($floorFile, \sprintf("%d\n", $lifted));
    \printf("Floor raised from %s to %d%% (tests/coverage-minimum.txt), commit it with the change.\n", $minimum, $lifted);
}
