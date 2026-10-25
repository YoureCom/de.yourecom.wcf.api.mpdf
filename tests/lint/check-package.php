<?php

/**
 * YoureCom Standard: checks package.xml and the generated files of the package. Managed by _dev/standard (apply.php),
 * do not edit here.
 *  - every required package is listed once, com.woltlab.wcf is one of them
 *  - com.woltlab.wcf is excluded from the next minor version on (`<excludedpackage version="6.3.0 Alpha 1">` for a
 *    minversion of 6.2.x), the package is not installable on a version it was not tested with
 *  - an update block has instructions, from the first version on (Alphas and Betas as well): deploy.sh syncs only these into
 *    the dev system, and no installed version can update without them (24.10.2026, clan.board.wbb)
 *  - every release of the series of the latest release reaches this version through an update block (releases: versions
 *    without Alpha/Beta/RC in the git history of package.xml), so 6.2.0 to 6.2.4 for 6.2.5 and for 6.3.0 alike; without
 *    one WoltLab refuses the update (Marcel 23.10.2026, multihunter.wbb)
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

$updates = $package->query('//w:instructions[@type="update"]') ?: [];

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

$fromVersions = [];
foreach ($updates as $update) {
    if ($update instanceof \DOMElement) {
        $fromVersions[] = $update->getAttribute('fromversion');
    }
}
if ($package->query('//w:instructions[@type="update"]/w:instruction')?->length === 0) {
    // the series of the latest release, the customers on it need the block; without a release the current one
    $releases = releases() ?? [];
    $version = $releases !== [] ? (string)\end($releases) : (\preg_match('~<version>\s*([^<]+)</version>~', (string)@\file_get_contents(ROOT . '/package.xml'), $m) ? \trim($m[1]) : '');
    $series = \preg_match('~^(\d+\.\d+)\.~', $version, $m) ? $m[1] : '6.2';
    $errors[] = 'package.xml: there is no <instructions type="update"> with instructions, deploy.sh syncs nothing into the dev system and no installed'
        . " version can update: add <instructions type=\"update\" fromversion=\"{$series}.*\"> with the instructions of the install";
} else {
    $releaseError = uncoveredReleases($fromVersions);
    if ($releaseError !== null) {
        $errors[] = $releaseError;
    }
}

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
    $git = gitOutput(...);
    $versionOf = static fn (?string $xml): ?string => $xml !== null && \preg_match('~<version>([^<]+)</version>~', $xml, $m) ? \trim($m[1]) : null;

    $current = $versionOf((string)@\file_get_contents(ROOT . '/package.xml'));
    if ($current === null || $git('rev-parse', '--is-shallow-repository') !== 'false' || $current !== $versionOf($git('show', 'HEAD:package.xml'))) {
        return null;
    }
    // --format=%H would lose its % to escapeshellarg() on Windows: the hash is the first word of --pretty=oneline; without
    // --diff-merges git skips merge commits, a version raised while resolving the version line of a merge (rollen/buero/code.md)
    // was not found and the merged files counted as changed without a version (shop, 24.10.2026)
    $base = \strtok((string)$git('log', '-1', '-s', '--pretty=oneline', '--no-decorate', '--diff-merges=first-parent', '-G<version>', '--', 'package.xml'), ' ');
    if ($base === false || $base === '') {
        return null;
    }
    // the base is a merge commit: its version may never lie below a side, and when both sides raised the version since their
    // merge base (a conflict in the version line) it must lie above both (rollen/buero/code.md: the higher Beta plus one),
    // otherwise one side's Beta would cover two different contents; a merge where only the branch raised the version keeps it
    $parents = \array_slice(\array_filter(\explode(' ', \trim((string)$git('rev-list', '--parents', '-n', '1', $base)))), 1);
    if (\count($parents) > 1) {
        $compare = compareVersions(...);
        $sides = \array_filter(\array_map(static fn (string $parent): ?string => $versionOf($git('show', "{$parent}:package.xml")), $parents));
        $mergeBase = \trim((string)$git('merge-base', ...$parents));
        $before = $mergeBase !== '' ? $versionOf($git('show', "{$mergeBase}:package.xml")) : null;
        $raised = \count(\array_filter($sides, static fn (string $side): bool => $before === null || $compare($side, $before) !== 0));
        foreach ($sides as $side) {
            $order = $compare($current, $side);
            if ($order < 0 || ($order === 0 && $raised > 1)) {
                return "package.xml: version {$current} resolves the merge " . \substr($base, 0, 7)
                    . ($raised > 1 ? ' but is not above both sides (' : ' but lies below one side (') . \implode(', ', $sides)
                    . '): take the higher Beta and raise it by one';
            }
        }
    }
    // compared with the working tree: committed and pending changes alike; tests, docs and dev files are not installed
    $changed = \array_filter(\explode("\n", (string)$git('diff', '--name-only', $base, '--', 'files', 'files_wcf', 'templates', 'acptemplates', 'language', ':(glob)*.xml', ':(exclude)package.xml', ':(exclude)phpunit*.xml')));
    $untracked = \array_filter(\explode("\n", (string)$git('ls-files', '--others', '--exclude-standard', '--', 'files', 'files_wcf', 'templates', 'acptemplates', 'language')));
    $changed = \array_values(\array_unique([...$changed, ...$untracked]));
    if ($changed === []) {
        return null;
    }

    return "package.xml: version {$current} was set in " . \substr($base, 0, 7) . ', since then ' . \count($changed) . ' installable file(s) changed ('
        . \implode(', ', \array_slice($changed, 0, 3)) . (\count($changed) > 3 ? ', ...' : '') . '): raise <version> (next Beta) and <date> with the change (php ../_dev/standard/bump-version.php .)';
}

/**
 * Names the releases of the series of the latest release that no update block reaches, or null (customers of an older
 * series are not checked). Releases are the versions without Alpha, Beta, RC or dev that package.xml ever had (git
 * history) below the current version, compared like the core does (Package::compareVersion() without spaces, so
 * 6.2.0 pl 1 counts; Package::checkFromversion(): `*` matches anything, a lone `*` is ignored). Outside a git repository
 * and in a shallow clone there is nothing to say; the CI checks out the whole history.
 *
 * @param list<string> $fromVersions
 */
function uncoveredReleases(array $fromVersions): ?string
{
    $releases = releases();
    if ($releases === null || $releases === []) {
        return null;
    }
    $series = \implode('.', \array_slice(\explode('.', (string)\end($releases)), 0, 2));

    $patterns = [];
    foreach ($fromVersions as $fromVersion) {
        if ($fromVersion !== '' && $fromVersion !== '*') {
            $patterns[] = '~^' . \str_replace('\*', '.*', \preg_quote($fromVersion, '~')) . '$~i';
        }
    }
    $missing = [];
    foreach ($releases as $release) {
        if (!\str_starts_with($release, $series . '.')) {
            continue;
        }
        $reached = false;
        foreach ($patterns as $pattern) {
            $reached = $reached || \preg_match($pattern, $release) === 1;
        }
        if (!$reached) {
            $missing[] = $release;
        }
    }
    if ($missing === []) {
        return null;
    }

    return 'package.xml: no update block reaches the released version(s) ' . \implode(', ', $missing)
        . ", customers on them could not update (WoltLab refuses it): add <instructions type=\"update\" fromversion=\"{$series}.*\">";
}

/**
 * Returns the releases (versions without Alpha, Beta, RC or dev below the current one) that package.xml ever had, oldest
 * first, compared like the core does (Package::compareVersion() without spaces); null outside a git repository and in a
 * shallow clone.
 *
 * @return list<string>|null
 */
function releases(): ?array
{
    if (gitOutput('rev-parse', '--is-shallow-repository') !== 'false') {
        return null;
    }
    $history = (string)gitOutput('log', '-p', '--no-color', '--no-ext-diff', '--diff-merges=first-parent', '-G<version>', '--', 'package.xml');
    \preg_match_all('~^\+\s*<version>([^<]+)</version>~m', $history, $matches);
    $current = \preg_match('~<version>([^<]+)</version>~', (string)@\file_get_contents(ROOT . '/package.xml'), $m) ? \trim($m[1]) : '';
    $compare = compareVersions(...);

    $releases = [];
    foreach ($matches[1] as $version) {
        $version = \trim($version);
        // a version not below the current one was never released (6.2.1 set early, later taken back to 6.2.1 Beta 1)
        if (
            \preg_match('~^\d+\.\d+\.\d+~', $version) === 1
            && \preg_match('~alpha|beta|rc|dev~i', $version) !== 1
            && $compare($version, $current) < 0
        ) {
            $releases[$version] = $version;
        }
    }
    \usort($releases, $compare);

    return $releases;
}

/**
 * Compares two versions like the core (Package::compareVersion(): spaces removed, Alpha/Beta/RC/dev/pl in lower case,
 * because version_compare() treats unknown spellings as equal).
 */
function compareVersions(string $a, string $b): int
{
    return \version_compare(\strtolower(\str_replace(' ', '', $a)), \strtolower(\str_replace(' ', '', $b)));
}

/**
 * Runs git in the package folder and returns its output, null if git fails (no repository, unknown revision).
 */
function gitOutput(string ...$arguments): ?string
{
    $command = 'git -C ' . \escapeshellarg(ROOT) . ' ' . \implode(' ', \array_map('escapeshellarg', $arguments)) . ' 2>' . (\PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
    \exec($command, $output, $code);

    return $code === 0 ? \implode("\n", $output) : null;
}
