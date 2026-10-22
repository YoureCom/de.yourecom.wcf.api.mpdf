<?php

/**
 * YoureCom Standard: comment rules (Marcel 21.10.2026, Sitzung). Managed by _dev/standard (apply.php), do not edit here.
 *  - templates (.tpl) and SCSS never have a comment (Smarty, HTML or C-style) - not even one line.
 *  - PHP and TypeScript: a comment is only allowed directly above a class/interface/trait/enum, a function/method or a
 *    property, and only one short descriptive line - PHPStan/TSDoc annotation lines (@param, @return, @var, @template,
 *    @throws, @inheritDoc, ...) do not count toward that line and may be as many as needed. Anywhere else (inline in a
 *    method body, floating, a file header) is a violation.
 *  - Every function/method needs either such a comment or #[\Override] (valid for a parent class, an interface or a
 *    trait method alike, PHP checks this itself at compile time) - never neither.
 *  - Magic methods (`__construct`, `__invoke`, `__get`, ...) only get `/** @inheritDoc *\/` (no descriptive line;
 *    magic-method-inheritdoc, Marcel 22.10.2026). Any other method never has a docblock of only @inheritDoc: it gets
 *    #[\Override] if it overrides something, otherwise a descriptive line (method-inheritdoc).
 *  - PHP docblocks: the first line (the description or @inheritDoc) is followed by an empty line before the annotation
 *    lines that PHPStan needs (comment-annotation-gap, Marcel 22.10.2026).
 *  - _dev/standard itself is exempt (tooling documentation needs more room, Marcel 21.10.2026: "ist mir egal").
 *
 * Known findings that cannot be fixed yet are listed in tests/lint/config.php (`commentsKnown`: relative file => list
 * of rules); entries that no longer match make the check fail, so the list can only shrink.
 *
 * Usage: php tests/lint/check-comments.php
 */

namespace yourecom\standard;

require __DIR__ . '/lib.php';

/** @var array<string, list<string>> $findings relative file => rules */
$findings = [];
/** @var array<string, string> $messages "file|rule" => message */
$messages = [];

$report = static function (string $file, string $rule, string $message) use (&$findings, &$messages): void {
    $findings[$file][] = $rule;
    $messages["{$file}|{$rule}"] ??= "{$file}: [{$rule}] {$message}";
};

/** Strips comment delimiters and asterisks, returns the descriptive lines (without PHPStan/TSDoc tags). */
$descriptive = static function (string $text): array {
    $lines = \preg_split('~\R~', $text) ?: [];
    $lines = \array_map(static fn (string $line): string => \trim($line, " \t*/"), $lines);
    $lines = \array_values(\array_filter($lines, static fn (string $line): bool => $line !== ''));

    return \array_values(\array_filter($lines, static fn (string $line): bool => !\preg_match('~^@[a-zA-Z]~', $line)));
};

/** Returns null if the descriptive text of a comment is at most one line. */
$tooLong = static function (string $text) use ($descriptive): ?string {
    return \count($descriptive($text)) > 1 ? 'mehr als eine beschreibende Zeile' : null;
};

/** Returns true if the first line (description or @inheritDoc) is directly followed by an annotation line. */
$missingGap = static function (string $text): bool {
    $lines = \array_map(static fn (string $line): string => \trim($line, " \t*/"), \preg_split('~\R~', $text) ?: []);
    foreach ($lines as $index => $line) {
        if ($line === '') {
            continue;
        }
        if (\preg_match('~^@[a-zA-Z]~', $line) && !\preg_match('~^@inheritDoc\b~i', $line)) {
            return false;
        }

        return \preg_match('~^@[a-zA-Z]~', $lines[$index + 1] ?? '') === 1;
    }

    return false;
};

// an attribute group "#[...]" can itself contain "]" inside strings/nested brackets; conservative bracket counting
$skipAttribute = static function (array $tokens, int $index, int $direction) use (&$skipAttribute): int {
    $open = $direction > 0 ? '[' : ']';
    $close = $direction > 0 ? ']' : '[';
    $depth = 1;
    $i = $index + $direction;
    for ($count = \count($tokens); $i >= 0 && $i < $count; $i += $direction) {
        $text = \is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        $depth += \substr_count($text, $open) - \substr_count($text, $close);
        if ($depth <= 0) {
            return $i + $direction;
        }
    }

    return $i;
};

foreach (files(['files', 'files_wcf'], ['.php']) as $relative => $path) {
    $tokens = \token_get_all((string)\file_get_contents($path));
    $count = \count($tokens);
    $declarationTokens = [\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM, \T_FUNCTION, \T_PUBLIC, \T_PROTECTED, \T_PRIVATE, \T_VAR, \T_READONLY, \T_CASE, \T_CONST, \T_FINAL, \T_ABSTRACT, \T_STATIC];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!\is_array($token) || !\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
            continue;
        }
        $line = $token[2];

        $j = $i + 1;
        while ($j < $count) {
            if (\is_array($tokens[$j]) && $tokens[$j][0] === \T_WHITESPACE) {
                $j++;
                continue;
            }
            if (\is_array($tokens[$j]) && $tokens[$j][0] === \T_ATTRIBUTE) {
                $j = $skipAttribute($tokens, $j, 1);
                continue;
            }
            break;
        }
        $next = $tokens[$j] ?? null;
        $onDeclaration = \is_array($next) && \in_array($next[0], $declarationTokens, true);

        if (!$onDeclaration) {
            $report($relative, 'stray-comment', "Zeile {$line}: Kommentar nicht direkt über Klasse/Funktion/Property, entfernen");
            continue;
        }
        $problem = $tooLong($token[1]);
        if ($problem !== null) {
            $report($relative, 'comment-too-long', "Zeile {$line}: {$problem}, ein kurzer Satz reicht");
        }
        if ($missingGap($token[1])) {
            $report($relative, 'comment-annotation-gap', "Zeile {$line}: Leerzeile zwischen erster Zeile (Beschreibung oder @inheritDoc) und den Annotationen fehlt");
        }
    }

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!\is_array($token) || $token[0] !== \T_FUNCTION) {
            continue;
        }
        $line = $token[2];
        $hasOverride = false;
        $hasComment = false;
        $comment = null;
        $j = $i - 1;
        while ($j >= 0) {
            $previous = $tokens[$j];
            if (\is_array($previous) && \in_array($previous[0], [\T_WHITESPACE, \T_PUBLIC, \T_PROTECTED, \T_PRIVATE, \T_STATIC, \T_ABSTRACT, \T_FINAL], true)) {
                $j--;
                continue;
            }
            if (\is_array($previous) && \in_array($previous[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                $hasComment = $tooLong($previous[1]) === null;
                $comment = $previous[1];
                break;
            }
            $text = \is_array($previous) ? $previous[1] : $previous;
            if ($text === ']') {
                $start = $skipAttribute($tokens, $j, -1);
                $group = '';
                for ($k = $start; $k <= $j; $k++) {
                    $group .= \is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                }
                if (\preg_match('~\\\\?Override\b~', $group)) {
                    $hasOverride = true;
                }
                $j = $start - 1;
                continue;
            }
            break;
        }
        $k = $i + 1;
        while (\is_array($tokens[$k] ?? null) && $tokens[$k][0] === \T_WHITESPACE) {
            $k++;
        }
        $name = \is_array($tokens[$k] ?? null) ? $tokens[$k][1] : '';
        $onlyInheritDoc = $comment !== null && \str_contains($comment, '@inheritDoc') && $descriptive($comment) === [];
        if (\str_starts_with($name, '__')) {
            if (!$onlyInheritDoc) {
                $report($relative, 'magic-method-inheritdoc', "Zeile {$line}: {$name}() braucht nur /** @inheritDoc */ (kein Text)");
            }
            continue;
        }
        if ($onlyInheritDoc) {
            $report($relative, 'method-inheritdoc', "Zeile {$line}: {$name}() mit @inheritDoc, stattdessen #[\\Override] (überschreibt) oder ein Satz (neue Methode)");
            continue;
        }
        if (!$hasOverride && !$hasComment) {
            $report($relative, 'function-needs-comment-or-override', "Zeile {$line}: Funktion ohne Kommentar und ohne #[\\Override]");
        }
    }
}

// TypeScript: no reliable tokenizer available, line-based heuristic (a comment line followed directly by a
// declaration-looking line counts as "on" it; anything else floating or inline is a violation)
foreach (files(['ts'], ['.ts']) as $relative => $path) {
    if (\str_ends_with($relative, '.d.ts')) {
        continue;
    }
    $lines = \preg_split('~\R~', (string)\file_get_contents($path)) ?: [];
    $inBlock = false;
    $blockStartLine = 0;
    $blockLines = [];
    foreach ($lines as $index => $rawLine) {
        $lineNo = $index + 1;
        $trimmed = \trim($rawLine);
        if ($inBlock) {
            $blockLines[] = $trimmed;
            if (\str_contains($trimmed, '*/')) {
                $inBlock = false;
                $after = \trim($lines[$index + 1] ?? '');
                $onDeclaration = (bool)\preg_match('~^(export\s+)?(default\s+)?(public\s+|private\s+|protected\s+|readonly\s+|static\s+|abstract\s+)*(class|interface|function|get\s|set\s|async\s|\w+[?!]?\s*[:(<])~', $after);
                if (!$onDeclaration) {
                    $report($relative, 'stray-comment', "Zeile {$blockStartLine}: Kommentar nicht direkt über Klasse/Funktion/Property, entfernen");
                } else {
                    $descriptive = \array_values(\array_filter(
                        \array_map(static fn (string $line): string => \trim($line, " \t*/"), $blockLines),
                        static fn (string $line): bool => $line !== '' && !\preg_match('~^@[a-zA-Z]~', $line),
                    ));
                    if (\count($descriptive) > 1) {
                        $report($relative, 'comment-too-long', "Zeile {$blockStartLine}: mehr als eine beschreibende Zeile, ein kurzer Satz reicht");
                    }
                }
            }
            continue;
        }
        if (\str_starts_with($trimmed, '/*')) {
            $inBlock = !\str_contains($trimmed, '*/');
            $blockStartLine = $lineNo;
            $blockLines = [$trimmed];
            if (!$inBlock) {
                $after = \trim($lines[$index + 1] ?? '');
                $onDeclaration = (bool)\preg_match('~^(export\s+)?(default\s+)?(public\s+|private\s+|protected\s+|readonly\s+|static\s+|abstract\s+)*(class|interface|function|get\s|set\s|async\s|\w+[?!]?\s*[:(<])~', $after);
                if (!$onDeclaration) {
                    $report($relative, 'stray-comment', "Zeile {$lineNo}: Kommentar nicht direkt über Klasse/Funktion/Property, entfernen");
                }
            }
            continue;
        }
        if (\str_starts_with($trimmed, '//')) {
            $report($relative, 'stray-comment', "Zeile {$lineNo}: `//`-Kommentar, in TS nie erlaubt (nur /** ... */ direkt über Klasse/Funktion/Property)");
        }
    }
}

// templates and SCSS: never a comment, not even one line
foreach (files(templateDirectories(), ['.tpl']) as $relative => $path) {
    $source = (string)\file_get_contents($path);
    if (\preg_match('~\{\*.*?\*\}~s', $source) || \preg_match('~<!--(?!\[if).*?-->~s', $source)) {
        $report($relative, 'template-comment', 'Template-Kommentar ({* *} oder <!-- -->), Templates haben nie Kommentare');
    }
}
foreach (files(['files/style', 'files_wcf/style'], ['.scss']) as $relative => $path) {
    $source = (string)\file_get_contents($path);
    if (\preg_match('~//|/\*~', $source)) {
        $report($relative, 'scss-comment', 'SCSS-Kommentar, SCSS hat nie Kommentare');
    }
}

$known = config()['commentsKnown'] ?? [];
$errors = [];
foreach ($findings as $file => $rules) {
    foreach (\array_unique($rules) as $rule) {
        if (!\in_array($rule, $known[$file] ?? [], true)) {
            $errors[] = $messages["{$file}|{$rule}"];
        }
    }
}
foreach ($known as $file => $rules) {
    foreach ($rules as $rule) {
        if (!\in_array($rule, $findings[$file] ?? [], true)) {
            $errors[] = "tests/lint/config.php: commentsKnown {$file} [{$rule}] no longer applies, remove it";
        }
    }
}

finish($errors, \count($findings) === 0 ? 'keine Kommentar-Verstöße' : \count($known) . ' bekannt');
