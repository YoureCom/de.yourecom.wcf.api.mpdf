<?php

/**
 * YoureCom Standard: checks the language files. Managed by _dev/standard (apply.php), do not edit here.
 *  - de.xml and en.xml define the same variables
 *  - German texts that address the reader have the informal and the formal variant; the text outside the informal
 *    branch (the formal one and everything around the conditions) has no "du" forms and does not start a sentence
 *    with an informal imperative ("Baue einen Brunnen")
 *  - every statically used variable with one of the prefixes of tests/lint/config.php (`languagePrefixes`) exists
 *  - the texts use no `{@...}` output and compare strictly in `{if}`/`{elseif}` (like the templates)
 *  - every item is filed under a category {lang}/getPhrase() can actually find at runtime: WCF's Language::get()
 *    only ever tries the item's first 3 dot-segments, then (if that category has no such item) its first 2 — any
 *    other category, or an item with fewer than 3 segments, silently always returns the raw key instead of the text.
 *    Known gaps (items whose category cannot be changed, e.g. shared with a required package) are listed in
 *    tests/lint/config.php (`languageCategoryKnown`: item name => the category it actually uses; the list can only
 *    shrink)
 *  (whether the texts compile is checked against the dev system: check-devsystem.php)
 *
 * Usage: php tests/lint/check-language.php
 */

namespace yourecom\standard;

require __DIR__ . '/lib.php';

// informal imperatives that start a sentence (the formal variant is "Wählen Sie ...")
const IMPERATIVES = 'Achte|Aktiviere|Bearbeite|Beachte|Bestätige|Brich|Deaktiviere|Drücke|Entferne|Erstelle|Fahre|Füge|Gehe|Gib|Klicke|Kaufe|Kehre|Kopiere|Lege|Lies|Lösche|Melde|Nimm|Nutze|Prüfe|Schließe|Sieh|Speichere|Starte|Trage|Verwende|Wähle|Warte|Wechsle|Öffne|Überprüfe|Übernimm|Baue|Verkaufe|Sammle|Lass|Probiere|Tippe|Setze|Ziehe|Schreibe|Gestalte|Finde|Bewege|Nenne|Vergib|Sende|Beginne|Beende';

/**
 * Removes the branches of `{if LANGUAGE_USE_INFORMAL_VARIANT}` that are only shown for "du" (the `{else}` part for
 * a negated condition), nested conditions inside them included.
 */
function withoutInformalBranches(string $text): string
{
    \preg_match_all('~\{(if|elseif)(?=[\s}])([^}]*)\}|\{(else)\}|\{/if\}~', $text, $tokens, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
    $cut = [];
    $stack = [];
    foreach ($tokens as $token) {
        $tag = $token[0][0];
        $offset = $token[0][1];
        $end = $offset + \strlen($tag);
        if (($token[1][0] ?? '') === 'if') {
            $informal = \str_contains($token[2][0], 'LANGUAGE_USE_INFORMAL_VARIANT');
            $negated = $informal && \str_starts_with(\ltrim($token[2][0]), '!');
            $stack[] = ['from' => $informal && !$negated ? $end : null, 'negated' => $negated];
        } elseif ($stack === []) {
            continue;
        } elseif (($token[1][0] ?? '') === 'elseif' || ($token[3][0] ?? '') === 'else') {
            $top = \count($stack) - 1;
            if ($stack[$top]['from'] !== null) {
                $cut[] = [$stack[$top]['from'], $offset];
                $stack[$top]['from'] = null;
            }
            if (($token[1][0] ?? '') === 'elseif' && \str_contains($token[2][0], 'LANGUAGE_USE_INFORMAL_VARIANT') && !\str_starts_with(\ltrim($token[2][0]), '!')) {
                $stack[$top]['from'] = $end;
            }
            if ($stack[$top]['negated'] && $tag === '{else}') {
                $stack[$top]['from'] = $end;
                $stack[$top]['negated'] = false;
            }
        } else {
            $top = \array_pop($stack);
            if ($top['from'] !== null) {
                $cut[] = [$top['from'], $offset];
            }
        }
    }
    \usort($cut, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    $outer = [];
    foreach ($cut as $range) {
        // a cut inside an earlier one is already gone
        if ($outer === [] || $range[0] >= $outer[\count($outer) - 1][1]) {
            $outer[] = $range;
        }
    }
    foreach (\array_reverse($outer) as [$from, $to]) {
        $text = \substr($text, 0, $from) . \substr($text, $to);
    }

    return $text;
}

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
    if (!\str_contains($item[2], 'LANGUAGE_USE_INFORMAL_VARIANT')) {
        if (\preg_match('~(?<![\w-])(Sie|Ihnen|Ihr\w*)(?![\w-])~u', $item[2], $address) === 1) {
            $errors[] = "de.xml: {$item[1]} addresses the reader (\"{$address[1]}\") without the LANGUAGE_USE_INFORMAL_VARIANT check";
            continue;
        }
        $rest = $item[2];
    } else {
        // the informal branches may use "du"; everything else (the formal branch and the text around the conditions) may not
        $rest = withoutInformalBranches($item[2]);
    }
    $rest = \preg_replace('~\{[^}]*\}|<[^>]*>~', ' ', $rest) ?? $rest;
    if (\preg_match('~(?<![\w-])(du|dich|dir|dein\w*)(?![\w-])~iu', $rest, $address) === 1) {
        $errors[] = "de.xml: {$item[1]} addresses the reader informally (\"{$address[1]}\") outside the LANGUAGE_USE_INFORMAL_VARIANT branch";
    } elseif (\preg_match('~(?:^\s*|[.!?:]\s+|\n\s*)(' . IMPERATIVES . ')(?![\w-])~u', $rest, $address) === 1) {
        $errors[] = "de.xml: {$item[1]} starts a sentence with the informal imperative \"{$address[1]}\" outside the LANGUAGE_USE_INFORMAL_VARIANT branch";
    }
}

$categoryKnown = $config['languageCategoryKnown'] ?? [];
$categoryKnownSeen = [];
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

        $category = $item->parentNode instanceof \DOMElement ? $item->parentNode->getAttribute('name') : '';
        $parts = \explode('.', $name);
        if (\count($parts) < 3) {
            $errors[] = "{$languageCode}.xml: {$name} has fewer than 3 dot-separated segments, {lang}/getPhrase() can never resolve it (always returns the raw key)";
            continue;
        }
        $expected = [$parts[0] . '.' . $parts[1], $parts[0] . '.' . $parts[1] . '.' . $parts[2]];
        if (\in_array($category, $expected, true)) {
            continue;
        }
        if (($categoryKnown[$name] ?? null) === $category) {
            $categoryKnownSeen[$name] = true;
            continue;
        }
        $errors[] = "{$languageCode}.xml: {$name} is filed under category \"{$category}\", but {lang}/getPhrase() only ever look in \"" . \implode('" or "', $expected) . "\" (always returns the raw key otherwise)";
    }
}
foreach ($categoryKnown as $name => $category) {
    if (!isset($categoryKnownSeen[$name])) {
        $errors[] = "tests/lint/config.php: languageCategoryKnown {$name} no longer applies, remove it";
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
