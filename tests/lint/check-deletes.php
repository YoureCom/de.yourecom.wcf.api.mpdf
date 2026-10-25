<?php

/**
 * YoureCom Standard: removed files and language items are deleted on update. Managed by _dev/standard (apply.php),
 * do not edit here.
 *  - every file of a released version that the package does not ship any more is listed in fileDelete.xml
 *    (files/, files_wcf/), templateDelete.xml (templates/) or acpTemplateDelete.xml (acptemplates/)
 *  - every language item of a released version that is gone is listed in the <delete> block of each language file
 *  - nothing that the package still ships is deleted (a file moved from files/ to files_wcf/ keeps its target)
 *  - a template of the file PIP (files/templates/*.tpl, files/acp/templates/*.tpl) renamed only in its casing needs no
 *    delete entry: the core loads it by its exact name, an old casing left on Linux is never read. Any other file keeps
 *    its delete entry (SCSS is collected by folder, acptemplates/ rows clash with the unique key of the template table)
 *  - an entry that deletes an old casing runs in every update before the files are extracted: on a case-insensitive
 *    file system (Windows) the old name is the shipped file, a delete afterwards removes it
 *
 * A released version is the state of the git history before <version> in package.xml changed (and the first commit),
 * as long as package.xml names this package: the history of another package the repository started from does not
 * count. Only those files can be on the systems of the customers; the core also deletes files that no package owns.
 *
 * Needs the full git history (CI: actions/checkout with fetch-depth: 0).
 *
 * Usage: php tests/lint/check-deletes.php [--fix]    --fix adds the missing entries (and the update instructions)
 */

namespace yourecom\standard;

require __DIR__ . '/lib.php';

const PIPS = [
    'files' => ['fileDelete', 'file'],
    'files_wcf' => ['fileDelete', 'file'],
    'templates' => ['templateDelete', 'template'],
    'acptemplates' => ['acpTemplateDelete', 'template'],
];

$fix = \in_array('--fix', $argv, true);

if (git(['rev-parse', '--is-inside-work-tree']) === null) {
    fail(['not a git repository, the delete check needs the history']);
}
if (git(['rev-parse', '--is-shallow-repository']) === 'true') {
    fail(['shallow clone, the delete check needs the full history (actions/checkout with fetch-depth: 0)']);
}

$applications = applications();
$errors = [];

// what the package ships now: pip => "application|entry" => true
$shipped = [];
// the same in lower case: pip => "application|entry" => shipped casing
$shippedFolded = [];
foreach (\explode("\n", (string)git(['-c', 'core.quotePath=false', 'ls-files'])) as $path) {
    if (($target = deleteTarget($path, $applications)) !== null) {
        $shipped[$target[0]]["{$target[1]}|{$target[2]}"] = true;
        $shippedFolded[$target[0]][\mb_strtolower("{$target[1]}|{$target[2]}")] = $target[2];
    }
}

$releases = releases();

// what a released version shipped and the package does not ship any more: pip => "application|entry" => [application, entry]
$removed = [];
foreach (releasedFiles($releases) as $path) {
    $target = deleteTarget($path, $applications);
    if (
        $target !== null
        && !isset($shipped[$target[0]]["{$target[1]}|{$target[2]}"])
        && !(isTemplateByName($target[0], $target[2]) && isset($shippedFolded[$target[0]][\mb_strtolower("{$target[1]}|{$target[2]}")]))
    ) {
        $removed[$target[0]]["{$target[1]}|{$target[2]}"] = [$target[1], $target[2]];
    }
}

foreach (['fileDelete' => 'file', 'templateDelete' => 'template', 'acpTemplateDelete' => 'template'] as $pip => $element) {
    // the core appends .tpl itself, an entry "name.tpl" looks for "name.tpl.tpl"
    if ($element === 'template' && \is_file(ROOT . "/{$pip}.xml")) {
        $source = (string)\file_get_contents(ROOT . "/{$pip}.xml");
        if (\preg_match_all('~<template\b[^>]*>([^<]*)\.tpl</template>~', $source, $suffixed) > 0) {
            if ($fix) {
                \file_put_contents(ROOT . "/{$pip}.xml", (string)\preg_replace('~(<template\b[^>]*>[^<]*)\.tpl(</template>)~', '$1$2', $source));
                echo "{$pip}.xml: removed .tpl from " . \count($suffixed[1]) . " entries\n";
            } else {
                foreach ($suffixed[1] as $name) {
                    $errors[] = "{$pip}.xml: {$name}.tpl, the core appends .tpl itself (write {$name})";
                }
            }
        }
    }

    $listed = deleteEntries($pip, $element, $applications);
    $missing = [];
    foreach ($removed[$pip] ?? [] as [$application, $entry]) {
        if (!isset($listed["{$application}|{$entry}"]) && !isset($listed["|{$entry}"])) {
            $missing[] = [$application, $entry];
        }
    }
    foreach ($listed as $key => $label) {
        [$application, $entry] = \explode('|', $key, 2);
        if (isset($shipped[$pip]["{$application}|{$entry}"]) || ($application === '' && isShippedAnywhere($shipped[$pip] ?? [], $entry))) {
            $errors[] = "{$pip}.xml: deletes {$label}, which the package still ships";
        } elseif (($casing = shippedCasing($shippedFolded[$pip] ?? [], $application, $entry)) !== null && !deleteRunsFirst($pip)) {
            $errors[] = "{$pip}.xml: deletes {$label}, the package ships {$casing}: on a case-insensitive file system that is the same file, remove the entry or run <instruction type=\"{$pip}\" /> first in every update";
        }
    }
    if ($missing === []) {
        continue;
    }

    if ($fix) {
        addDeleteEntries($pip, $element, $missing);
        addUpdateInstruction($pip);
        echo "{$pip}.xml: added " . \count($missing) . " entr" . (\count($missing) === 1 ? 'y' : 'ies') . "\n";
    } else {
        foreach ($missing as [$application, $entry]) {
            $casing = shippedCasing($shippedFolded[$pip] ?? [], $application, $entry);
            $errors[] = "{$pip}.xml: {$entry} ({$application}) was removed, delete it on update"
                . ($casing !== null ? " (renamed to {$casing}: run <instruction type=\"{$pip}\" /> first in every update)" : '');
        }
    }
}

// language items of the released versions
$languageFiles = \glob(ROOT . '/language/*.xml') ?: [];
$requests = [];
foreach (\array_keys($releases) as $commit) {
    foreach ($languageFiles as $file) {
        $requests[] = "{$commit}:language/" . \basename($file);
    }
}
$removedItems = [];
// many releases share the same version of a language file: read every version once
$blobs = \array_values(\array_unique(\array_filter(gitObjects($requests, contents: false))));
foreach (gitObjects($blobs) as $source) {
    \preg_match_all('~<item name="([^"]+)"~', (string)\preg_replace('~<delete>.*?</delete>~s', '', (string)$source), $matches);
    foreach ($matches[1] as $name) {
        // WoltLab rejects names with leading or trailing whitespace, such an item never existed in a database
        if ($name === \trim($name)) {
            $removedItems[$name] = true;
        }
    }
}
foreach ($languageFiles as $file) {
    $languageFile = 'language/' . \basename($file);
    $language = xml($file);
    if ($language === null) {
        continue;
    }
    $imported = [];
    foreach ($language->query('//w:category/w:item') ?: [] as $item) {
        $imported[$item instanceof \DOMElement ? $item->getAttribute('name') : ''] = true;
    }
    $deleted = [];
    foreach ($language->query('/w:language/w:delete/w:item') ?: [] as $item) {
        $name = $item instanceof \DOMElement ? $item->getAttribute('name') : '';
        $deleted[$name] = true;
        if (isset($imported[$name])) {
            $errors[] = "{$languageFile}: deletes {$name}, which the file still imports";
        }
    }

    $missing = \array_keys(\array_diff_key($removedItems, $imported, $deleted));
    \sort($missing);
    if ($missing === []) {
        continue;
    }
    if ($language->query('/w:language/w:import')?->length === 0) {
        $errors[] = "{$languageFile}: uses the old format without <import>, convert it to delete the removed items " . \implode(', ', $missing);
    } elseif ($fix) {
        addLanguageDeletes($file, $missing);
        echo "{$languageFile}: added " . \count($missing) . " delete entr" . (\count($missing) === 1 ? 'y' : 'ies') . "\n";
    } else {
        foreach ($missing as $name) {
            $errors[] = "{$languageFile}: the item {$name} was removed, delete it on update (<delete><item name=\"{$name}\" /></delete>)";
        }
    }
}

finish($errors, \count($releases) . ' released versions');

/**
 * Returns the commits of the released versions of this package (commit => true): the first commit and the commit
 * before every change of <version>, on the first-parent line, where package.xml names this package.
 *
 * @return array<string, true>
 */
function releases(): array
{
    $commits = \array_values(\array_filter(\explode("\n", (string)git(['rev-list', '--first-parent', '--reverse', 'HEAD']))));
    if ($commits === []) {
        return [];
    }
    $order = \array_flip($commits);

    $candidates = [$commits[0] => true];
    $changes = \array_filter(\explode("\n", (string)git(['log', '--first-parent', '--format=%H', '-G<version>', '--', 'package.xml'])));
    foreach ($changes as $change) {
        $index = $order[$change] ?? 0;
        if ($index > 0) {
            $candidates[$commits[$index - 1]] = true;
        }
    }

    $name = packageName();
    $candidates = \array_keys($candidates);
    $releases = [];
    foreach (gitObjects(\array_map(static fn (string $commit): string => "{$commit}:package.xml", $candidates)) as $index => $source) {
        if ($source !== null && \preg_match('~<package name="([^"]+)"~', $source, $match) && \strcasecmp($match[1], $name) === 0) {
            $releases[$candidates[$index]] = true;
        }
    }

    // in the order of the history
    \uksort($releases, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);

    return $releases;
}

/**
 * Returns the paths below files/, files_wcf/, templates/ and acptemplates/ that one of the releases contained.
 * Replays the changes of the first-parent history once instead of listing the tree of every release.
 *
 * @param array<string, true> $releases in the order of the history
 * @return list<string>
 */
function releasedFiles(array $releases): array
{
    $pending = \array_keys($releases);
    $log = (string)git(['-c', 'core.quotePath=false', 'log', '--first-parent', '--diff-merges=first-parent', '--reverse', '--relative', '--no-renames', '--name-status', '--format=>%H', '--', ...\array_keys(PIPS)]);
    $commits = \array_flip(\array_values(\array_filter(\explode("\n", (string)git(['rev-list', '--first-parent', '--reverse', 'HEAD'])))));

    $state = [];
    $released = [];
    foreach (\explode("\n", $log) as $line) {
        if (\str_starts_with($line, '>')) {
            // a release before this commit sees the state up to here
            $index = $commits[\substr($line, 1)] ?? \PHP_INT_MAX;
            while ($pending !== [] && ($commits[$pending[0]] ?? -1) < $index) {
                $released += $state;
                \array_shift($pending);
            }
            continue;
        }
        $parts = \explode("\t", $line, 2);
        if (\count($parts) !== 2) {
            continue;
        }
        if ($parts[0] === 'D') {
            unset($state[$parts[1]]);
        } else {
            $state[$parts[1]] = true;
        }
    }
    if ($pending !== []) {
        $released += $state;
    }

    return \array_map('strval', \array_keys($released));
}

/**
 * Reads objects like "<commit>:<path>" from the repository in one git process: their contents, or with
 * `contents: false` their object ids; null for a missing object.
 *
 * @param list<string> $objects
 * @return list<string|null>
 */
function gitObjects(array $objects, bool $contents = true): array
{
    if ($objects === []) {
        return [];
    }

    // requests and answer go through files: a pipe could block while git writes, and pipes are slow on Windows
    $requests = (string)\tempnam(\sys_get_temp_dir(), 'yourecom-git-');
    $answer = (string)\tempnam(\sys_get_temp_dir(), 'yourecom-git-');
    \file_put_contents($requests, \implode("\n", $objects) . "\n");
    $process = \proc_open(
        ['git', '-C', (string)\realpath(ROOT), 'cat-file', $contents ? '--batch' : '--batch-check'],
        [0 => ['file', $requests, 'r'], 1 => ['file', $answer, 'w'], 2 => ['file', \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']],
        $pipes
    );
    if (!\is_resource($process)) {
        \unlink($requests);
        \unlink($answer);
        fail(['git cat-file failed']);
    }
    \proc_close($process);
    $output = (string)\file_get_contents($answer);
    \unlink($requests);
    \unlink($answer);

    $results = [];
    $offset = 0;
    foreach ($objects as $object) {
        $end = \strpos($output, "\n", $offset);
        if ($end === false) {
            $results[] = null;
            continue;
        }
        $header = \substr($output, $offset, $end - $offset);
        $offset = $end + 1;
        if (!\preg_match('~^([0-9a-f]+) \w+ (\d+)$~', $header, $match)) {
            $results[] = null;
            continue;
        }
        if (!$contents) {
            $results[] = $match[1];
            continue;
        }
        $results[] = \substr($output, $offset, (int)$match[2]);
        $offset += (int)$match[2] + 1;
    }

    return $results;
}

/**
 * Returns the delete PIP, the application and the entry for a path of the repository, null if it is not installed.
 *
 * @param array<string, string> $applications
 * @return array{string, string, string}|null
 */
function deleteTarget(string $path, array $applications): ?array
{
    $parts = \explode('/', $path, 2);
    if (\count($parts) !== 2 || !isset(PIPS[$parts[0]])) {
        return null;
    }
    [$directory, $relative] = $parts;
    if (PIPS[$directory][1] === 'template') {
        if (!\str_ends_with($relative, '.tpl') || \str_contains($relative, '/')) {
            return null;
        }
        $relative = \substr($relative, 0, -4);
    }

    return [PIPS[$directory][0], $applications[$directory], $relative];
}

/**
 * Returns the entries of a delete PIP file: "application|entry" => label (application empty if not given).
 *
 * @param array<string, string> $applications
 * @return array<string, string>
 */
function deleteEntries(string $pip, string $element, array $applications): array
{
    $entries = [];
    foreach (xml(ROOT . "/{$pip}.xml")?->query("/w:data/w:delete/w:{$element}") ?: [] as $node) {
        if ($node instanceof \DOMElement) {
            $entry = \trim($node->textContent);
            $entries[$node->getAttribute('application') . '|' . $entry] = $entry . ($node->getAttribute('application') !== '' ? " ({$node->getAttribute('application')})" : '');
        }
    }

    return $entries;
}

/**
 * Tells whether an entry without application is shipped into any application.
 *
 * @param array<string, true> $shipped
 */
function isShippedAnywhere(array $shipped, string $entry): bool
{
    foreach (\array_keys($shipped) as $key) {
        if (\explode('|', $key, 2)[1] === $entry) {
            return true;
        }
    }

    return false;
}

/**
 * Returns the shipped casing of an entry that differs from it only in its casing, null if there is none (an entry without
 * application matches any application).
 *
 * @param array<string, string> $shippedFolded
 */
function shippedCasing(array $shippedFolded, string $application, string $entry): ?string
{
    $folded = \mb_strtolower($entry);
    foreach ($shippedFolded as $key => $casing) {
        [$shippedApplication, $shippedEntry] = \explode('|', $key, 2);
        if ($shippedEntry === $folded && ($application === '' || $shippedApplication === \mb_strtolower($application))) {
            return $casing;
        }
    }

    return null;
}

/**
 * Tells whether an entry is a template that the file PIP installs and the core only loads by its exact name.
 */
function isTemplateByName(string $pip, string $entry): bool
{
    return $pip === 'fileDelete' && \preg_match('~^(acp/)?templates/[^/]+\.tpl$~', $entry) === 1;
}

/**
 * Tells whether every update block of package.xml runs the delete PIP before its first instruction of the install PIP
 * (fileDelete before `file`, templateDelete before `template`, acpTemplateDelete before `acpTemplate`), so that an old
 * casing is gone before the shipped file is extracted.
 */
function deleteRunsFirst(string $pip): bool
{
    $install = \lcfirst(\substr($pip, 0, -\strlen('Delete')));
    $package = xml(ROOT . '/package.xml');
    foreach ($package?->query('//w:instructions[@type="update"]') ?: [] as $update) {
        foreach ($package?->query('w:instruction', $update) ?: [] as $instruction) {
            $type = $instruction instanceof \DOMElement ? $instruction->getAttribute('type') : '';
            if ($type === $pip) {
                continue 2;
            }
            if ($type === $install) {
                return false;
            }
        }
    }

    return true;
}

/**
 * Adds entries to a delete PIP file (creates it if needed), keeps its line endings and indentation.
 *
 * @param list<array{string, string}> $entries [application, entry]
 */
function addDeleteEntries(string $pip, string $element, array $entries): void
{
    $file = ROOT . "/{$pip}.xml";
    $source = \is_file($file) ? (string)\file_get_contents($file) : "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
        . "<data xmlns=\"http://www.woltlab.com\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xsi:schemaLocation=\"http://www.woltlab.com https://www.woltlab.com/XSD/6.0/{$pip}.xsd\">\n"
        . "</data>\n";
    $eol = \str_contains($source, "\r\n") ? "\r\n" : "\n";
    $indent = \preg_match('~^([ \t]+)<delete>~m', $source, $match) ? $match[1] : "\t";
    \usort($entries, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    $lines = \array_map(
        static fn (array $entry): string => "{$indent}{$indent}<{$element} application=\"{$entry[0]}\">" . \htmlspecialchars($entry[1], \ENT_XML1) . "</{$element}>",
        $entries
    );

    \file_put_contents($file, insertLines($source, $lines, 'data', $indent, $eol));
}

/**
 * Inserts lines at the end of the <delete> block, or adds the block before the closing root tag.
 *
 * @param list<string> $lines
 */
function insertLines(string $source, array $lines, string $root, string $indent, string $eol): string
{
    $block = \implode($eol, $lines);
    if (\str_contains($source, '</delete>')) {
        return (string)\preg_replace_callback('~([ \t]*)</delete>~', static fn (array $match): string => $block . $eol . $match[1] . '</delete>', $source, 1);
    }

    return (string)\preg_replace_callback("~</{$root}>~", static fn (): string => "{$indent}<delete>{$eol}{$block}{$eol}{$indent}</delete>{$eol}</{$root}>", $source, 1);
}

/**
 * Adds `<instruction type="..." />` to every update block of package.xml that does not have it.
 */
function addUpdateInstruction(string $pip): void
{
    $file = ROOT . '/package.xml';
    $source = (string)\file_get_contents($file);
    $eol = \str_contains($source, "\r\n") ? "\r\n" : "\n";
    $updated = (string)\preg_replace_callback(
        '~(<instructions\s+type="update"[^>]*>)(.*?)(\r?\n[ \t]*</instructions>)~s',
        static function (array $block) use ($pip, $eol): string {
            if (\str_contains($block[2], "type=\"{$pip}\"")) {
                return $block[0];
            }
            $indent = \preg_match('~\n([ \t]+)<instruction\b~', $block[2], $match) ? $match[1] : "\t\t";

            return $block[1] . $block[2] . "{$eol}{$indent}<instruction type=\"{$pip}\" />" . $block[3];
        },
        $source
    );
    if ($updated !== $source) {
        \file_put_contents($file, $updated);
    }
}

/**
 * Adds `<item name="..."/>` entries to the <delete> block of a language file (creates the block if needed).
 *
 * @param list<string> $names
 */
function addLanguageDeletes(string $file, array $names): void
{
    $source = (string)\file_get_contents($file);
    $eol = \str_contains($source, "\r\n") ? "\r\n" : "\n";
    $indent = \preg_match('~^([ \t]+)<import>~m', $source, $match) ? $match[1] : "\t";
    $lines = \array_map(static fn (string $name): string => "{$indent}{$indent}<item name=\"" . \htmlspecialchars($name, \ENT_XML1) . '" />', $names);

    \file_put_contents($file, insertLines($source, $lines, 'language', $indent, $eol));
}
