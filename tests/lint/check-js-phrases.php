<?php

/**
 * YoureCom Standard: every phrase the TypeScript modules read with getPhrase() exists in both language files and is
 * preloaded, either in the bootstrap (`PreloadPhrasesCollecting`, preferred) or with `{jsphrase}` / `registerPhrase()` in a template.
 * With `jsPhrasesDynamic` in tests/lint/config.php, phrases whose key is built at runtime are tolerated: calls without a
 * literal name and preloaded phrases without a literal use are no errors then.
 * Managed by _dev/standard (apply.php), do not edit here.
 *
 * Usage: php tests/lint/check-js-phrases.php
 */

namespace yourecom\standard;

require __DIR__ . '/lib.php';

\preg_match_all('~->preload\(\s*[\'"]([^\'"]+)[\'"]~', bootstrap(), $matches);
$preloaded = \array_fill_keys($matches[1], 'bootstrap');
foreach (files(templateDirectories(), ['.tpl']) as $relative => $path) {
    \preg_match_all('~\{jsphrase name=[\'"]([^\'"]+)[\'"]~', (string)\file_get_contents($path), $matches);
    $preloaded += \array_fill_keys($matches[1], $relative);
    \preg_match_all('~registerPhrase\(\s*[\'"]([^\'"]+)[\'"]~', (string)\file_get_contents($path), $matches);
    $preloaded += \array_fill_keys($matches[1], $relative);
}

// phrases whose key is built at runtime cannot be followed, tests/lint/config.php: 'jsPhrasesDynamic' => true
$dynamicKeys = (bool)(config()['jsPhrasesDynamic'] ?? false);

$dynamicKeys = (bool)(config()['jsPhrasesDynamic'] ?? false);
$languages = ['de' => \array_flip(languageKeys('de')), 'en' => \array_flip(languageKeys('en'))];
$used = [];
$errors = [];

foreach (files(['ts'], ['.ts']) as $relative => $path) {
    \preg_match_all('~getPhrase\(([^)]*)\)~', (string)\file_get_contents($path), $calls);
    foreach ($calls[1] as $arguments) {
        if (!\preg_match_all('~["\']([a-z]+\.[\w.]+)["\']~', $arguments, $keys)) {
            if ($dynamicKeys) {
                continue;
            }

            $errors[] = "{$relative}: getPhrase() without a literal phrase name, the check cannot follow it";
            continue;
        }

        foreach ($keys[1] as $key) {
            $used[$key] = $relative;
        }
    }
}

foreach ($used as $key => $file) {
    $isOwn = isset($languages['de'][$key]) || isset($languages['en'][$key]);
    if (!$isOwn && \str_starts_with($key, 'wcf.')) {
        // phrases of the core are preloaded by the core
        continue;
    }

    foreach ($languages as $languageCode => $items) {
        if (!isset($items[$key])) {
            $errors[] = "{$file}: the phrase {$key} is missing in language/{$languageCode}.xml";
        }
    }

    if (!isset($preloaded[$key])) {
        $errors[] = "{$file}: the phrase {$key} is not preloaded (bootstrap or {jsphrase})";
    }
}

foreach ($dynamicKeys ? [] : \array_diff_key($preloaded, $used) as $key => $source) {
    if (!isset($languages['de'][$key]) && !isset($languages['en'][$key])) {
        // a phrase of the core, preloaded for a core module (e.g. the worker dialog)
        continue;
    }

    $errors[] = "{$source} preloads {$key}, but no TypeScript module uses it";
}

finish($errors, \count($used) . ' phrases');
