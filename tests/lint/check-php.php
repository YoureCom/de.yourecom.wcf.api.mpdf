<?php

/**
 * YoureCom Standard: rules for the PHP code of the package. Managed by _dev/standard (apply.php), do not edit here.
 *  - no debug output (`wcfDebug()`, `var_dump()`, `dump()`, `dd()`)
 *  - no `WCF_N`, the database layer replaces `wcf1_` itself
 *  - `json_decode()` with `JSON_THROW_ON_ERROR` (or `JSON::decode()`), a broken document must not become null silently
 *  - `StringUtil::trim()` instead of the native `trim()` (it also removes Unicode whitespace); `trim()` with an own
 *    character list stays allowed
 *  - `fputcsv()`, `fgetcsv()` and `str_getcsv()` pass the escape parameter; PHP 8.4 deprecates the default, WoltLab turns
 *    the deprecation into an error and the CSV export breaks
 *  - classes, interfaces, traits and enums have a docblock with a description, `@author` and `@license`
 *  - notifications (`files/lib/system/user/notification/`) link to an ACP controller of the package only with
 *    `'isACP' => true`; without it LinkHandler takes the frontend in the frontend and in cronjobs (the link leads nowhere)
 *  - a `'message-id'` of a notification event (`files/lib/system/user/notification/event/`) contains the notification ID
 *    (or a comment or response ID), or is left out (the core then makes a unique one); a second mail with the same
 *    Message-ID is dropped silently by Gmail and others (IUserNotificationEvent::getEmailMessage(): it MUST never repeat)
 *
 * Methods (`#[\Override]` or a docblock) and strict comparisons are checked by PHPStan (phpstan.standard.neon).
 *
 * Usage: php tests/lint/check-php.php
 */

namespace yourecom\standard;

require __DIR__ . '/lib.php';

$errors = [];
$count = 0;
$acpControllers = acpControllers();

/** position of the escape parameter (1-based) of the CSV functions */
const CSV_ARGUMENTS = ['fputcsv' => 5, 'fgetcsv' => 5, 'str_getcsv' => 4];

foreach (files(['files', 'files_wcf'], ['.php']) as $relative => $path) {
    $source = (string)\file_get_contents($path);
    $count++;

    foreach (functionCalls($source, ['wcfdebug', 'var_dump', 'dump', 'dd', 'json_decode', 'trim', ...\array_keys(CSV_ARGUMENTS)]) as $call) {
        if (isset(CSV_ARGUMENTS[$call['name']])) {
            if (!csvEscapeGiven($call['name'], $call['arguments'])) {
                $errors[] = "{$relative}:{$call['line']}: {$call['name']}() without the escape parameter (PHP 8.4 deprecates the default, write '' for RFC 4180)";
            }
        } elseif ($call['name'] === 'trim') {
            if (argumentCount($call['arguments']) === 1) {
                $errors[] = "{$relative}:{$call['line']}: native trim(), use StringUtil::trim()";
            }
        } elseif ($call['name'] !== 'json_decode') {
            $errors[] = "{$relative}:{$call['line']}: debug output {$call['name']}()";
        } elseif (!\str_contains($call['arguments'], 'JSON_THROW_ON_ERROR')) {
            $errors[] = "{$relative}:{$call['line']}: json_decode() without JSON_THROW_ON_ERROR (or use JSON::decode())";
        }
    }

    if (\str_starts_with($relative, 'files/lib/system/user/notification/')) {
        foreach (acpLinksWithoutIsAcp($source, $acpControllers) as [$line, $controller]) {
            $errors[] = "{$relative}:{$line}: getLink('{$controller}') links to an ACP controller without 'isACP' => true (e-mails also 'isEmail' => true)";
        }
    }

    if (\str_starts_with($relative, 'files/lib/system/user/notification/event/')) {
        foreach (messageIdsWithoutUniquePart($source) as [$line, $value]) {
            $errors[] = "{$relative}:{$line}: 'message-id' => {$value} repeats for a second mail about the same object, append \$this->getNotification()->notificationID (or leave it out)";
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
 * Returns the controller names of the ACP pages, forms and actions of the package (`FooForm` => `Foo`).
 *
 * @return list<string>
 */
function acpControllers(): array
{
    $controllers = [];
    foreach (files(['files/lib/acp/page', 'files/lib/acp/form', 'files/lib/acp/action'], ['.class.php']) as $relative => $path) {
        $controllers[] = (string)\preg_replace('~(Page|Form|Action)$~', '', \basename($relative, '.class.php'));
    }

    return \array_values(\array_unique($controllers));
}

/**
 * Returns the `->getLink()` calls on an ACP controller of the package whose arguments do not set `isACP` to true.
 *
 * @param list<string> $acpControllers
 * @return list<array{0: int, 1: string}> line and controller
 */
function acpLinksWithoutIsAcp(string $source, array $acpControllers): array
{
    $tokens = \token_get_all($source);
    $found = [];
    foreach ($tokens as $index => $token) {
        if (!\is_array($token) || $token[0] !== \T_STRING || \strtolower($token[1]) !== 'getlink') {
            continue;
        }
        $previous = nextToken($tokens, $index, -1);
        $next = nextToken($tokens, $index, 1);
        if ($previous === null || !\is_array($tokens[$previous]) || !\in_array($tokens[$previous][0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }
        if ($next === null || $tokens[$next] !== '(') {
            continue;
        }
        $first = nextToken($tokens, $next, 1);
        if ($first === null || !\is_array($tokens[$first]) || $tokens[$first][0] !== \T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $controller = \substr($tokens[$first][1], 1, -1);
        if (!\in_array($controller, $acpControllers, true)) {
            continue;
        }

        // the source of the arguments up to the matching parenthesis, without whitespace and comments
        $depth = 0;
        $arguments = '';
        for ($i = $next, $count = \count($tokens); $i < $count; $i++) {
            if (\is_array($tokens[$i]) && \in_array($tokens[$i][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $text = \is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            if ($text === '(' || $text === '[') {
                $depth++;
            } elseif ($text === ')' || $text === ']') {
                $depth--;
            }
            $arguments .= $text;
            if ($depth === 0) {
                break;
            }
        }
        if (!\preg_match('~[\'"]isACP[\'"]=>true~i', $arguments)) {
            $found[] = [$token[2], $controller];
        }
    }

    return $found;
}

/**
 * Returns true if a call of a CSV function passes the escape parameter, by position, by name or through unpacking.
 */
function csvEscapeGiven(string $name, string $arguments): bool
{
    if (\preg_match('~(?<![\w$])escape\s*:~', $arguments) === 1 || \str_contains($arguments, '...')) {
        return true;
    }

    return argumentCount($arguments) >= CSV_ARGUMENTS[$name];
}

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

/**
 * Returns the `'message-id'` entries of a notification event whose value contains no part that never repeats
 * (notificationID, commentID or responseID). A value that is a plain variable is followed to its assignment in the file.
 *
 * @return list<array{0: int, 1: string}> line and value
 */
function messageIdsWithoutUniquePart(string $source): array
{
    $tokens = \token_get_all($source);
    $found = [];
    foreach ($tokens as $index => $token) {
        if (!\is_array($token) || $token[0] !== \T_CONSTANT_ENCAPSED_STRING || \strtolower(\trim($token[1], '\'"')) !== 'message-id') {
            continue;
        }
        $arrow = nextToken($tokens, $index, 1);
        if ($arrow === null || !\is_array($tokens[$arrow]) || $tokens[$arrow][0] !== \T_DOUBLE_ARROW) {
            continue;
        }
        $value = expressionAfter($tokens, $arrow + 1, [',', ']', ')', ';']);
        if (\preg_match('~^\$(\w+)$~', $value, $match)) {
            foreach ($tokens as $i => $t) {
                if (\is_array($t) && $t[0] === \T_VARIABLE && $t[1] === '$' . $match[1]) {
                    $next = nextToken($tokens, $i, 1);
                    if ($next !== null && $tokens[$next] === '=') {
                        $value .= ' = ' . expressionAfter($tokens, $next + 1, [';']);
                    }
                }
            }
        }
        if (!\preg_match('~notificationID|commentID|responseID~', $value)) {
            $found[] = [$token[2], (string)\preg_replace('~\s+~', ' ', $value)];
        }
    }

    return $found;
}

/**
 * Returns the source of the expression starting at $start up to the first of $ends outside of brackets.
 *
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 * @param list<string> $ends
 */
function expressionAfter(array $tokens, int $start, array $ends): string
{
    $depth = 0;
    $text = '';
    for ($i = $start, $n = \count($tokens); $i < $n; $i++) {
        $part = \is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        if ($depth === 0 && \in_array($part, $ends, true)) {
            break;
        }
        if (\in_array($part, ['(', '[', '{'], true) || (\is_array($tokens[$i]) && \in_array($tokens[$i][0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
        } elseif (\in_array($part, [')', ']', '}'], true)) {
            $depth--;
        }
        $text .= $part;
    }

    return \trim($text);
}
