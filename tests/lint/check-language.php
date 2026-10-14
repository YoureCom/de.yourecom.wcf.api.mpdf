<?php

/**
 * YoureCom Standard: checks the language files. Managed by _dev/standard (apply.php), do not edit here.
 *  - de.xml and en.xml define the same variables
 *  - German texts that address the reader have the informal and the formal variant
 *  - every statically used variable with one of the prefixes of tests/lint/config.php (`languagePrefixes`) exists
 *  - the texts use no `{@...}` output and compare strictly in `{if}`/`{elseif}` (like the templates)
 *  (whether the texts compile is checked against the dev system: check-devsystem.php)
 *
 * Usage: php tests/lint/check-language.php
 */

namespace yourecom\standard;

require __DIR__ . '/lib.php';

$config = config();
$de = languageKeys('de');
$en = languageKeys('en');
$errors = [];

foreach (\array_diff($de, $en) as $key) {
    $errors[] = "missing in en.xml: {$key}";
}
foreach (\array_diff($en, $de) as $key) {
    $errors[] = "missing in de.xml: {$key}";
}

\preg_match_all('~<item name="([^"]+)"><!\[CDATA\[(.*?)\]\]></item>~s', \is_file(ROOT . '/language/de.xml') ? (string)\file_get_contents(ROOT . '/language/de.xml') : '', $items, \PREG_SET_ORDER);
foreach ($items as $item) {
    if (\str_contains($item[2], 'LANGUAGE_USE_INFORMAL_VARIANT')) {
        continue;
    }
    if (\preg_match('~(?<![\w-])(du|dich|dir|dein\w*|Sie|Ihnen|Ihr\w*)(?![\w-])~u', $item[2], $address) === 1) {
        $errors[] = "de.xml: {$item[1]} addresses the reader (\"{$address[1]}\") without the LANGUAGE_USE_INFORMAL_VARIANT check";
    }
}

foreach (['de', 'en'] as $languageCode) {
    foreach (xml(ROOT . "/language/{$languageCode}.xml")?->query('//w:category/w:item') ?: [] as $item) {
        if (!$item instanceof \DOMElement) {
            continue;
        }
        $name = $item->getAttribute('name');
        if (\str_contains($item->textContent, '{@')) {
            $errors[] = "{$languageCode}.xml: {$name} uses {@...}, use {unsafe:...}";
        }
        foreach (looseConditions($item->textContent) as [, $problem]) {
            $errors[] = "{$languageCode}.xml: {$name}: {$problem}";
        }
    }
}

$prefixes = $config['languagePrefixes'];
if ($prefixes !== []) {
    $known = \array_flip($de);
    // names that are a prefix of a defined variable are used to build dynamic names
    $partial = [];
    foreach ($de as $key) {
        $parts = \explode('.', $key);
        for ($i = 1, $count = \count($parts); $i < $count; $i++) {
            $partial[\implode('.', \array_slice($parts, 0, $i))] = true;
        }
    }

    $pattern = \implode('|', \array_map(static fn (string $prefix): string => \preg_quote($prefix, '~'), $prefixes));
    $used = [];
    foreach (files(['files', 'files_wcf', 'templates', 'acptemplates', 'ts'], ['.php', '.tpl', '.ts']) as $relative => $path) {
        \preg_match_all("~['\"{}]((?:{$pattern})[A-Za-z0-9_.]*)['\"{]~", (string)\file_get_contents($path), $matches);
        foreach ($matches[1] as $key) {
            if (!\str_ends_with($key, '.')) {
                $used[$key][$relative] = true;
            }
        }
    }

    \ksort($used);
    foreach ($used as $key => $files) {
        if (!isset($known[$key]) && !isset($partial[$key]) && !\in_array($key, $config['languageIgnore'], true)) {
            $errors[] = "unknown language variable {$key} (" . \implode(', ', \array_keys($files)) . ')';
        }
    }
}

finish($errors, \count($de) . ' variables');
