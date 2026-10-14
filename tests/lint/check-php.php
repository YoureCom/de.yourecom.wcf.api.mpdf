<?php

/**
 * YoureCom Standard: rules for the PHP code of the package. Managed by _dev/standard (apply.php), do not edit here.
 *  - no debug output (`wcfDebug()`, `var_dump()`, `dump()`, `dd()`)
 *  - no `WCF_N`, the database layer replaces `wcf1_` itself
 *  - `json_decode()` with `JSON_THROW_ON_ERROR` (or `JSON::decode()`), a broken document must not become null silently
 *  - `StringUtil::trim()` instead of the native `trim()` (it also removes Unicode whitespace); `trim()` with an own
 *    character list stays allowed
 *  - classes, interfaces, traits and enums have a docblock with a description, `@author` and `@license`
 *
 * Methods (`#[\Override]` or a docblock) and strict comparisons are checked by PHPStan (phpstan.standard.neon).
 *
 * Usage: php tests/lint/check-php.php
 */

namespace yourecom\standard;

require __DIR__ . '/lib.php';

$errors = [];
$count = 0;

foreach (files(['files', 'files_wcf'], ['.php']) as $relative => $path) {
    $source = (string)\file_get_contents($path);
    $count++;

    foreach (functionCalls($source, ['wcfdebug', 'var_dump', 'dump', 'dd', 'json_decode', 'trim']) as $call) {
        if ($call['name'] === 'trim') {
            if (argumentCount($call['arguments']) === 1) {
                $errors[] = "{$relative}:{$call['line']}: native trim(), use StringUtil::trim()";
            }
        } elseif ($call['name'] !== 'json_decode') {
            $errors[] = "{$relative}:{$call['line']}: debug output {$call['name']}()";
        } elseif (!\str_contains($call['arguments'], 'JSON_THROW_ON_ERROR')) {
            $errors[] = "{$relative}:{$call['line']}: json_decode() without JSON_THROW_ON_ERROR (or use JSON::decode())";
        }
    }

    $tokens = \token_get_all($source);
    foreach ($tokens as $index => $token) {
        if (!\is_array($token)) {
            continue;
        }

        if (\in_array($token[0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true) && \ltrim($token[1], '\\') === 'WCF_N') {
            $errors[] = "{$relative}:{$token[2]}: WCF_N, write wcf1_ (the database layer replaces the prefix)";
            continue;
        }

        if (!\in_array($token[0], [\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM], true)) {
            continue;
        }
        // `Foo::class`, `new class` and enum used as an identifier are no declarations
        $previous = nextToken($tokens, $index, -1);
        $next = nextToken($tokens, $index, 1);
        if ($next === null || !\is_array($tokens[$next]) || $tokens[$next][0] !== \T_STRING) {
            continue;
        }
        if ($previous !== null && \is_array($tokens[$previous]) && \in_array($tokens[$previous][0], [\T_DOUBLE_COLON, \T_NEW], true)) {
            continue;
        }

        $name = $tokens[$next][1];
        $docComment = classDocComment($tokens, $index);
        if ($docComment === null) {
            $errors[] = "{$relative}:{$token[2]}: {$name} has no docblock (description, @author, @license)";
            continue;
        }

        $missing = [];
        if (!\preg_match('~^\s*\*\s*[^@\s*/]~m', $docComment)) {
            $missing[] = 'a description';
        }
        foreach (['@author', '@license'] as $tag) {
            if (!\str_contains($docComment, $tag)) {
                $missing[] = $tag;
            }
        }
        if ($missing !== []) {
            $errors[] = "{$relative}:{$token[2]}: the docblock of {$name} lacks " . \implode(', ', $missing);
        }
    }
}

finish($errors, "{$count} files");

/**
 * Returns the number of arguments of a call, given the source of its parenthesized argument list.
 */
function argumentCount(string $arguments): int
{
    $depth = 0;
    $count = 0;
    $hasContent = false;
    foreach (\token_get_all('<?php ' . $arguments) as $token) {
        if (\is_array($token) && \in_array($token[0], [\T_OPEN_TAG, \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
            continue;
        }
        $text = \is_array($token) ? $token[1] : $token;
        if ($text === '(' || $text === '[' || $text === '{') {
            $depth++;
            if ($depth === 1) {
                continue;
            }
        } elseif ($text === ')' || $text === ']' || $text === '}') {
            $depth--;
        } elseif ($text === ',' && $depth === 1) {
            $count++;
            $hasContent = false;
            continue;
        }
        if ($depth >= 1) {
            $hasContent = true;
        }
    }

    // a trailing comma does not start another argument
    return $count + ($hasContent ? 1 : 0);
}

/**
 * Returns the docblock in front of a class declaration (before its modifiers and attributes), null if there is none.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 */
function classDocComment(array $tokens, int $index): ?string
{
    $depth = 0;
    for ($i = $index - 1; $i >= 0; $i--) {
        $token = $tokens[$i];
        $text = \is_array($token) ? $token[1] : $token;

        // skip attributes: #[ ... ]
        if ($text === ']') {
            $depth++;
            continue;
        }
        if ($depth > 0) {
            if ($text === '[' || (\is_array($token) && $token[0] === \T_ATTRIBUTE)) {
                $depth--;
            }
            continue;
        }

        if (\is_array($token) && $token[0] === \T_DOC_COMMENT) {
            return $token[1];
        }
        if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_FINAL, \T_ABSTRACT, \T_READONLY], true)) {
            continue;
        }

        return null;
    }

    return null;
}
