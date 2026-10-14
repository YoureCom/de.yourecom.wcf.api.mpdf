<?php

/**
 * YoureCom Standard: line coverage of the PHPUnit unit suite (Clover report) against the floor in
 * tests/coverage-minimum.txt. The floor only goes up. Managed by _dev/standard (apply.php), do not edit here.
 *
 * Usage: php tests/lint/check-coverage.php var/coverage.xml
 */

namespace yourecom\standard;

require __DIR__ . '/lib.php';

$report = $argv[1] ?? 'var/coverage.xml';
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
$minimum = (float)\trim((string)@\file_get_contents(ROOT . '/tests/coverage-minimum.txt'));

\printf("Coverage of the unit suite: %.2f%% (%d of %d statements), floor %.2f%%\n", $percent, $covered, $statements, $minimum);
if ($percent + 0.005 < $minimum) {
    fail(['The coverage is below the floor.']);
}
