<?php

/**
 * YoureCom Standard: shared helpers of the lint scripts. Managed by _dev/standard (apply.php), do not edit here.
 *
 * Reads the package name from package.xml and the plugin specific settings from tests/lint/config.php.
 */

namespace yourecom\standard;

const ROOT = __DIR__ . '/../..';

/**
 * Returns the plugin specific settings merged with the defaults.
 *
 * @return array{
 *     languagePrefixes: list<string>,
 *     languageIgnore: list<string>,
 *     xsdKnown: array<string, list<string>>,
 *     architectureKnown: array<string, list<string>>,
 *     commentsKnown: array<string, list<string>>,
 *     jsBuildDir: string|null,
 *     jsPhrasesDynamic: bool,
 * }
 */
function config(): array
{
    $file = ROOT . '/tests/lint/config.php';
    $config = \is_file($file) ? require $file : [];

    return [
        'languagePrefixes' => $config['languagePrefixes'] ?? [],
        'languageIgnore' => $config['languageIgnore'] ?? [],
        'xsdKnown' => $config['xsdKnown'] ?? [],
        'architectureKnown' => $config['architectureKnown'] ?? [],
        'commentsKnown' => $config['commentsKnown'] ?? [],
        'jsBuildDir' => $config['jsBuildDir'] ?? null,
        'jsPhrasesDynamic' => $config['jsPhrasesDynamic'] ?? false,
        'coreTables' => $config['coreTables'] ?? [],
    ];
}

/**
 * Returns the package identifier from package.xml.
 */
function packageName(): string
{
    $source = (string)\file_get_contents(ROOT . '/package.xml');
    if (!\preg_match('~<package name="([^"]+)"~', $source, $match)) {
        fail(['package.xml has no package name']);
    }

    return $match[1];
}

/**
 * Returns the source of the bootstrap file of the package (empty if there is none).
 */
function bootstrap(): string
{
    $name = packageName();
    foreach (['files', 'files_wcf'] as $directory) {
        foreach (\glob(ROOT . "/{$directory}/lib/bootstrap/*.php") ?: [] as $file) {
            if (\strcasecmp(\basename($file, '.php'), $name) === 0) {
                return (string)\file_get_contents($file);
            }
        }
    }

    return '';
}

/**
 * Returns the item names of a language file.
 *
 * @return list<string>
 */
function languageKeys(string $languageCode): array
{
    $file = ROOT . "/language/{$languageCode}.xml";
    if (!\is_file($file)) {
        return [];
    }

    $source = (string)\file_get_contents($file);
    \preg_match_all('~<item name="([^"]+)"~', $source, $matches);

    return $matches[1];
}

/**
 * Returns the directories with templates: `templates/` and `acptemplates/` (template PIPs) and templates an application
 * ships as files (`files/templates/`, `files/acp/templates/`).
 *
 * @return list<string>
 */
function templateDirectories(): array
{
    return ['templates', 'acptemplates', 'files/templates', 'files/acp/templates'];
}

/**
 * Yields the files below the given directories of the plugin whose name ends with one of the suffixes. Bundled
 * third-party code (`vendor/` directories) is skipped, like PHP-CS-Fixer does.
 *
 * @param list<string> $directories
 * @param list<string> $suffixes
 * @return \Generator<string, string> relative path => absolute path
 */
function files(array $directories, array $suffixes): \Generator
{
    $root = \str_replace('\\', '/', (string)\realpath(ROOT));
    foreach ($directories as $directory) {
        if (!\is_dir(ROOT . '/' . $directory)) {
            continue;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ROOT . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = \str_replace('\\', '/', (string)\realpath($file->getPathname()));
            if (\str_contains(\substr($path, \strlen($root)), '/vendor/')) {
                continue;
            }
            foreach ($suffixes as $suffix) {
                if (\str_ends_with($path, $suffix)) {
                    yield \substr($path, \strlen($root) + 1) => $path;
                    break;
                }
            }
        }
    }
}

/**
 * Returns the calls of the given global functions in a PHP source (not methods, not declarations).
 *
 * @param list<string> $names lower case function names
 * @return list<array{name: string, line: int, arguments: string}>
 */
function functionCalls(string $source, array $names): array
{
    $tokens = \token_get_all($source);
    $calls = [];
    foreach ($tokens as $index => $token) {
        if (!\is_array($token) || !\in_array($token[0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }
        $name = \strtolower(\ltrim($token[1], '\\'));
        if (!\in_array($name, $names, true)) {
            continue;
        }

        $next = nextToken($tokens, $index, 1);
        $previous = nextToken($tokens, $index, -1);
        if ($next === null || $tokens[$next] !== '(') {
            continue;
        }
        if ($previous !== null && \is_array($tokens[$previous])
            && \in_array($tokens[$previous][0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NEW, \T_CONST], true)) {
            continue;
        }

        // the source of the arguments up to the matching parenthesis
        $depth = 0;
        $arguments = '';
        for ($i = $next, $count = \count($tokens); $i < $count; $i++) {
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

        $calls[] = ['name' => $name, 'line' => $token[2], 'arguments' => $arguments];
    }

    return $calls;
}

/**
 * Returns the index of the next (direction 1) or previous (-1) token that is no whitespace or comment.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
 */
function nextToken(array $tokens, int $index, int $direction): ?int
{
    for ($i = $index + $direction; isset($tokens[$i]); $i += $direction) {
        if (!\is_array($tokens[$i]) || !\in_array($tokens[$i][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
            return $i;
        }
    }

    return null;
}

/**
 * Loads an XML file of the plugin, null if it does not exist or is not well-formed.
 */
function xml(string $file): ?\DOMXPath
{
    if (!\is_file($file)) {
        return null;
    }

    $document = new \DOMDocument();
    $previous = \libxml_use_internal_errors(true);
    $loaded = $document->load($file);
    \libxml_clear_errors();
    \libxml_use_internal_errors($previous);
    if (!$loaded) {
        return null;
    }

    $xpath = new \DOMXPath($document);
    $xpath->registerNamespace('w', 'http://www.woltlab.com');

    return $xpath;
}

/**
 * Returns the application each source directory of the plugin is installed into (files, files_wcf, templates,
 * acptemplates): the application attribute of the install instruction, else the package itself for applications,
 * else wcf.
 *
 * @return array<string, string>
 */
function applications(string $pluginDir = ROOT): array
{
    $xpath = xml($pluginDir . '/package.xml');
    $own = 'wcf';
    if ($xpath !== null && \trim((string)$xpath->evaluate('string(//w:packageinformation/w:isapplication)')) === '1') {
        $name = (string)$xpath->evaluate('string(/w:package/@name)');
        $own = \strtolower(\substr($name, (int)\strrpos($name, '.') + 1));
    }

    $applications = ['files' => $own, 'files_wcf' => 'wcf', 'templates' => $own, 'acptemplates' => $own];
    $types = ['file' => 'files', 'template' => 'templates', 'acpTemplate' => 'acptemplates'];
    foreach ($xpath?->query('//w:instructions[@type="install"]/w:instruction') ?: [] as $instruction) {
        if (!$instruction instanceof \DOMElement || !isset($types[$instruction->getAttribute('type')])) {
            continue;
        }
        $archive = \trim($instruction->textContent);
        $directory = $archive !== '' ? \basename($archive, '.tar') : $types[$instruction->getAttribute('type')];
        if ($instruction->getAttribute('application') !== '' && isset($applications[$directory])) {
            $applications[$directory] = $instruction->getAttribute('application');
        }
    }

    return $applications;
}

/**
 * Builds constants.php of a plugin: the constants of its options (option.xml), of the application and of the
 * framework, so PHPStan and the IDE know them. Written by apply.php, checked by check-package.php.
 * The option constants have the types of the core's options.inc.php (OptionEditor::rebuild()): boolean and integer
 * options are int, all others string.
 */
function constantsSource(string $pluginDir): string
{
    $pluginDir = \str_replace('\\', '/', (string)\realpath($pluginDir));
    $groups = ['0' => [], "''" => []];
    $options = xml($pluginDir . '/option.xml');
    foreach ($options?->query('//w:import/w:options/w:option') ?: [] as $option) {
        if (!$option instanceof \DOMElement || $option->getAttribute('name') === '') {
            continue;
        }
        $type = \strtolower(\trim((string)$options->evaluate('string(w:optiontype)', $option)));
        $value = \in_array($type, ['boolean', 'integer'], true) ? '0' : "''";
        $groups[$value][\strtoupper($option->getAttribute('name'))] = $value;
    }

    $lines = [
        '<?php',
        '/**',
        ' * Defines constants for autocompletion in IDEs. This file is not meant to be actively used anywhere!',
        ' *',
        ' * @author  Marcel Beckers',
        ' * @license YoureCom License - Commercial (YCLC)  <https://www.yourecom.de/shop/licence-commercial/>',
        ' * @package ' . \basename($pluginDir),
        ' */',
        '',
        '// option constants',
        '',
        '// application constants',
    ];
    $package = xml($pluginDir . '/package.xml');
    if ($package !== null && \trim((string)$package->evaluate('string(//w:packageinformation/w:isapplication)')) === '1') {
        $name = (string)$package->evaluate('string(/w:package/@name)');
        if (\preg_match('~^[^.]+\.[^.]+\.(.+)$~', $name, $match)) {
            $key = \strtoupper($match[1]);
            $lines[] = "define('{$key}_DIR', '');";
            $lines[] = "define('RELATIVE_{$key}_DIR', '');";
        }
    }
    \array_push(
        $lines,
        '',
        '// hardcoded framework constants',
        "define('WCF_DIR', '');",
        "define('LEGAL_NOTICE_EMAIL_ADDRESS', '');",
        "define('DOMPDF_LOG_FILE', WCF_DIR . 'tmp/dompdf.log');",
    );
    foreach ($groups as $constants) {
        \ksort($constants, \SORT_STRING);
        foreach ($constants as $constant => $value) {
            $lines[] = "define('{$constant}', {$value});";
        }
    }

    return \implode("\n", $lines) . "\n";
}

/**
 * Returns the define() lines of a constants.php source, sorted, to compare two versions independent of the order.
 *
 * @return list<string>
 */
function constantDefinitions(string $source): array
{
    \preg_match_all('~^define\(.*$~m', \str_replace("\r", '', $source), $matches);
    $definitions = \array_map('trim', $matches[0]);
    \sort($definitions);

    return $definitions;
}

/**
 * Returns the loose comparisons in the conditions ({if}, {elseif}) of a template or language item:
 * list of [offset, problem].
 *
 * @return list<array{int, string}>
 */
function looseConditions(string $source): array
{
    $problems = [];
    \preg_match_all('~\{(?:if|elseif)\s[^}]*\}~', $source, $conditions, \PREG_OFFSET_CAPTURE);
    foreach ($conditions[0] as [$condition, $offset]) {
        // strings in the condition do not count
        $code = (string)\preg_replace('~\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"~', "''", $condition);
        if (\preg_match('~(?<![=!<>])(==|!=|<>)(?!=)~', $code, $operator)) {
            $strict = $operator[1] === '==' ? '===' : '!==';
            $problems[] = [$offset, "loose comparison {$operator[1]} in {$condition}, use {$strict}"];
        }
        if (\preg_match('~\|empty\b|\bempty\s*\(~', $code)) {
            $problems[] = [$offset, "empty in {$condition}, compare strictly (=== [], === '', === 0, === null)"];
        }
    }

    return $problems;
}

/**
 * Runs git in the plugin directory, null if git fails (no repository).
 *
 * @param list<string> $arguments
 */
function git(array $arguments): ?string
{
    // without a shell: escapeshellarg() on Windows replaces "%" (e.g. in --format=%H); the answer goes through a
    // file, pipes are slow on Windows
    $answer = (string)\tempnam(\sys_get_temp_dir(), 'yourecom-git-');
    $process = \proc_open(
        ['git', '-C', (string)\realpath(ROOT), ...$arguments],
        [1 => ['file', $answer, 'w'], 2 => ['file', \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']],
        $pipes
    );
    if (!\is_resource($process)) {
        \unlink($answer);

        return null;
    }
    $status = \proc_close($process);
    $output = (string)\file_get_contents($answer);
    \unlink($answer);

    return $status === 0 ? \rtrim(\str_replace("\r\n", "\n", $output), "\n") : null;
}

/**
 * Prints the errors and exits with status 1, prints "OK" otherwise.
 *
 * @param list<string> $errors
 */
function finish(array $errors, string $summary = ''): never
{
    if ($errors !== []) {
        fail($errors);
    }

    echo 'OK' . ($summary !== '' ? " ({$summary})" : '') . "\n";

    exit(0);
}

/**
 * Prints the errors and exits with status 1.
 *
 * @param list<string> $errors
 */
function fail(array $errors): never
{
    \fwrite(\STDERR, \implode("\n", $errors) . "\n");

    exit(1);
}
