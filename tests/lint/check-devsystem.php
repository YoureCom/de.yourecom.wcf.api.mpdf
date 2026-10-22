<?php

/**
 * YoureCom Standard: checks against the local dev system. Managed by _dev/standard (apply.php), do not edit here.
 *  - every permission the package asks for exists (a typo locks everybody out without an error): getPermission(),
 *    checkPermissions() and the $neededPermissions/$permissions* properties in PHP and templates, <permissions> in
 *    the PIP files; known are the user group options of the dev system and of userGroupOption.xml
 *  - every template and every language item compiles with the template compiler of the core
 *
 * Needs the dev system (environment variable WCF_DIR, default C:/laragon/www/wsc6.2), so it is not part of the CI;
 * check.sh runs it unless --fast is given.
 *
 * Usage: php tests/lint/check-devsystem.php
 */

namespace yourecom\standard;

use wcf\system\language\LanguageFactory;
use wcf\system\WCF;

require __DIR__ . '/lib.php';

$wcfDir = \getenv('WCF_DIR');
$wcfDir = \rtrim(\is_string($wcfDir) && $wcfDir !== '' ? $wcfDir : 'C:/laragon/www/wsc6.2', '/\\');
if (!\is_file($wcfDir . '/global.php')) {
    fail(["No WoltLab Suite installation found in '{$wcfDir}', set WCF_DIR."]);
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
require $wcfDir . '/global.php';

$errors = [];

// permissions: name => list of places
$used = [];
$collect = static function (string $fragment, string $place) use (&$used): void {
    \preg_match_all('~[\'"]([a-z]+(?:\.\w+)+)[\'"]~', $fragment, $names);
    foreach ($names[1] as $name) {
        $used[$name][] = $place;
    }
};
foreach (files(['files', 'files_wcf', 'templates', 'acptemplates'], ['.php', '.tpl']) as $relative => $path) {
    $source = (string)\file_get_contents($path);
    \preg_match_all(
        '~\b(?:getPermission|getNeutralPermission|checkPermissions|hasPermission)\s*\(\s*(\[[^\]]*\]|\'[^\']*\'|"[^"]*")'
        . '|\$(?:neededPermissions|permissions(?:Create|Update|Delete|View)?)\s*=\s*(\[[^\]]*\])~',
        $source,
        $matches,
        \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE
    );
    foreach ($matches as $match) {
        $line = \substr_count($source, "\n", 0, $match[0][1]) + 1;
        $collect($match[0][0], "{$relative}:{$line}");
    }
}
foreach (\glob(ROOT . '/*.xml') ?: [] as $file) {
    $source = (string)\file_get_contents($file);
    \preg_match_all('~<permissions>([^<]*)</permissions>~', $source, $matches, \PREG_OFFSET_CAPTURE);
    foreach ($matches[1] as [$list, $offset]) {
        $line = \substr_count($source, "\n", 0, $offset) + 1;
        foreach (\array_filter(\array_map('trim', \explode(',', $list))) as $name) {
            $used[$name][] = \basename($file) . ":{$line}";
        }
    }
}

$known = [];
$sql = 'SELECT optionName FROM wcf1_user_group_option';
$statement = WCF::getDB()->prepare($sql);
$statement->execute();
foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $name) {
    $known[(string)$name] = true;
}
// options of this package that are not deployed yet
foreach (xml(ROOT . '/userGroupOption.xml')?->query('//w:option') ?: [] as $option) {
    if ($option instanceof \DOMElement) {
        $known[$option->getAttribute('name')] = true;
    }
}

\ksort($used);
foreach ($used as $name => $places) {
    if (!isset($known[$name])) {
        $errors[] = "unknown permission {$name} (" . \implode(', ', \array_unique($places)) . ')';
    }
}

// templates
$compiler = WCF::getTPL()->getCompiler();
$templates = 0;
foreach (files(templateDirectories(), ['.tpl']) as $relative => $path) {
    $templates++;
    try {
        $compiler->compileString(\basename($path, '.tpl'), (string)\file_get_contents($path), [], true);
    } catch (\Throwable $exception) {
        // the compiler of the frontend only knows frontend templates: an ACP template may include an ACP-only one
        $isAcp = \str_starts_with($relative, 'acptemplates/') || \str_starts_with($relative, 'files/acp/templates/');
        if (
            $isAcp
            && \preg_match("~^Unable to find template '([^']+)'$~", \trim($exception->getMessage()), $match) === 1
            && (\is_file(ROOT . '/acptemplates/' . $match[1] . '.tpl') || \is_file(WCF_DIR . 'acp/templates/' . $match[1] . '.tpl'))
        ) {
            continue;
        }
        $errors[] = "{$relative}: does not compile: " . \trim($exception->getMessage());
    }
}

// language items
$languageCompiler = LanguageFactory::getInstance()->getScriptingCompiler();
$items = 0;
foreach (['de', 'en'] as $languageCode) {
    foreach (xml(ROOT . "/language/{$languageCode}.xml")?->query('//w:category/w:item') ?: [] as $item) {
        if (!$item instanceof \DOMElement || !\str_contains($item->textContent, '{')) {
            continue;
        }
        $items++;
        try {
            $languageCompiler->compileString($item->getAttribute('name'), $item->textContent, [], true);
        } catch (\Throwable $exception) {
            $errors[] = "language/{$languageCode}.xml: {$item->getAttribute('name')} does not compile: " . \trim($exception->getMessage());
        }
    }
}

finish($errors, \count($used) . " permissions, {$templates} templates, {$items} language items");
