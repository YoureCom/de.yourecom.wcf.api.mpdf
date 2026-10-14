<?php

/**
 * YoureCom Standard: rules for templates and the frontend. Managed by _dev/standard (apply.php), do not edit here.
 *  - no jQuery in templates and TypeScript
 *  - no legacy AJAX actions (`Ajax.dboAction`, `WCF.Action.Proxy`) in TypeScript, new server calls are RPC endpoints
 *  - no compiled .js files next to the TypeScript sources
 *  - no `{@...}` output (WoltLab 6.1+: `{unsafe:...}` makes unescaped output explicit)
 *  - strict comparisons in `{if}`/`{elseif}`: `===`/`!==` instead of `==`/`!=`, no `|empty`/`empty()`
 *    (compare with `=== []`, `=== ''`, `=== 0` or `=== null`)
 *  - no debug output (`|wcfDebug`) and no legacy phrases (`Language.addObject(`, phrases come from `{jsphrase}`)
 *  - HTML: no duplicate attributes in a tag, no duplicate static ids in a template, `<button>` with a `type`,
 *    form fields with a label (`<label for>`, an enclosing `<label>`, `aria-label`, `aria-labelledby` or `title`;
 *    fields with a dynamic id are labelled by the including template)
 *
 * Usage: php tests/lint/check-templates.php
 */

namespace yourecom\standard;

require __DIR__ . '/lib.php';

$errors = [];

foreach (files(templateDirectories(), ['.tpl']) as $relative => $path) {
    // comments do not count, their line breaks stay for the line numbers
    $source = (string)\preg_replace_callback('~\{\*.*?\*\}~s', static fn (array $match): string => \str_repeat("\n", \substr_count($match[0], "\n")), (string)\file_get_contents($path));
    $line = static fn (int $offset): int => \substr_count($source, "\n", 0, $offset) + 1;

    foreach (\explode("\n", $source) as $index => $text) {
        $number = $index + 1;
        if (\preg_match('~\bjQuery\b|\$\(\s*[\'"#.]~', $text)) {
            $errors[] = "{$relative}:{$number}: jQuery";
        }
        if (\preg_match('~\{@~', $text)) {
            $errors[] = "{$relative}:{$number}: {@...} is deprecated, use {unsafe:...}";
        }
        if (\preg_match('~\|wcfDebug\b~', $text)) {
            $errors[] = "{$relative}:{$number}: debug output |wcfDebug";
        }
        if (\str_contains($text, 'Language.addObject(')) {
            $errors[] = "{$relative}:{$number}: Language.addObject(), preload the phrases with {jsphrase}";
        }
    }

    foreach (looseConditions($source) as [$offset, $problem]) {
        $errors[] = "{$relative}:{$line($offset)}: {$problem}";
    }

    // the HTML tags, without Smarty tags that contain a ">" (conditions)
    \preg_match_all('~<([a-zA-Z][\w-]*)\b((?:[^<>{]|\{[^}]*\})*)>~', $source, $tags, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
    $ids = [];
    $labelFor = [];
    \preg_match_all('~\bfor="([^"]+)"~', $source, $labels);
    foreach ($labels[1] as $for) {
        $labelFor[$for] = true;
    }

    foreach ($tags as $tag) {
        $name = \strtolower($tag[1][0]);
        $attributes = $tag[2][0];
        $offset = $tag[0][1];
        $number = $line($offset);
        // attributes that depend on a condition can exist twice ({if}a="1"{else}a="2"{/if})
        $conditional = \preg_match('~\{(?:if|else|elseif)\b~', $attributes) === 1;
        $static = (string)\preg_replace('~\{[^}]*\}~', '', $attributes);

        \preg_match_all('~(?:^|\s)([a-zA-Z_:][-\w:.]*)(?=\s*=)~', $static, $names);
        if (!$conditional) {
            $duplicates = \array_unique(\array_diff_assoc(\array_map('strtolower', $names[1]), \array_unique(\array_map('strtolower', $names[1]))));
            if ($duplicates !== []) {
                $errors[] = "{$relative}:{$number}: <{$name}> has the attribute " . \implode(', ', $duplicates) . ' twice';
            }
        }

        if (\preg_match('~(?:^|\s)id="([A-Za-z][\w:.-]*)"~', $attributes, $id)) {
            $ids[$id[1]][] = $offset;
        }

        if ($name === 'button' && !\preg_match('~(?:^|\s)type\s*=~', $attributes)) {
            $errors[] = "{$relative}:{$number}: <button> without type (the default is submit)";
        }

        if (\in_array($name, ['input', 'select', 'textarea'], true) && !hasLabel($name, $attributes, $source, $offset, $labelFor)) {
            $errors[] = "{$relative}:{$number}: <{$name}> without a label (<label for>, enclosing <label>, aria-label, aria-labelledby or title)";
        }
    }

    foreach ($ids as $id => $offsets) {
        for ($i = 1, $count = \count($offsets); $i < $count; $i++) {
            // the same id in the branches of a condition is fine
            $between = \substr($source, $offsets[$i - 1], $offsets[$i] - $offsets[$i - 1]);
            if (!\preg_match('~\{(?:else|elseif)\b~', $between)) {
                $errors[] = "{$relative}:{$line($offsets[$i])}: the id \"{$id}\" exists twice in the template";
            }
        }
    }
}

foreach (files(['ts'], ['.ts', '.js']) as $relative => $path) {
    if (\str_ends_with($relative, '.js')) {
        $errors[] = "{$relative}: compiled JavaScript next to the TypeScript sources";
        continue;
    }

    foreach (\file($path) ?: [] as $index => $text) {
        $number = $index + 1;
        if (\preg_match('~\bjQuery\b|from "jquery"~', $text)) {
            $errors[] = "{$relative}:{$number}: jQuery";
        }
        if (\preg_match('~\bdboAction\(|WCF\.Action\.Proxy~', $text)) {
            $errors[] = "{$relative}:{$number}: legacy AJAX action, use an RPC endpoint";
        }
    }
}

finish($errors);

/**
 * Tells whether a form field has an accessible label.
 *
 * @param array<string, true> $labelFor the values of all for="..." attributes of the template
 */
function hasLabel(string $name, string $attributes, string $source, int $offset, array $labelFor): bool
{
    if ($name === 'input' && \preg_match('~(?:^|\s)type="(?:hidden|submit|button|reset|image|\{[^"]*)"~i', $attributes)) {
        return true;
    }
    if (\preg_match('~(?:^|\s)(?:aria-label|aria-labelledby|title)\s*=~', $attributes)) {
        return true;
    }
    // a dynamic id is labelled by the including template (e.g. option types inside the <label for> of the core)
    if (\preg_match('~(?:^|\s)id="([^"]+)"~', $attributes, $id) && (isset($labelFor[$id[1]]) || \str_contains($id[1], '{'))) {
        return true;
    }

    // inside a <label> that is not closed before the field
    $before = \substr($source, 0, $offset);
    $open = \strrpos($before, '<label');

    return $open !== false && !\str_contains(\substr($before, $open), '</label>');
}
