<?php

/**
 * YoureCom Standard: architecture rules (close to the core of WoltLab Suite 6.2). Managed by _dev/standard (apply.php),
 * do not edit here.
 *  - list-page:     lists are AbstractGridViewPage (ACP) or AbstractListViewPage, not SortablePage/MultipleLinkPage
 *  - object-action: no `{objectAction}`/`jsObjectActionContainer` in templates, actions are interactions with RPC endpoints
 *  - clipboard:     no clipboard actions, mass actions are bulk interactions of the grid/list view
 *  - toggle-action: no IToggleAction/TDatabaseObjectToggle, enabling and disabling are RPC endpoints with a command
 *  - ajax-method:   no custom `validate*()` AJAX entry points in AbstractDatabaseObjectAction classes (RPC endpoints)
 *  - endpoint-validate-action: RPC endpoints never call validateAction() (it rejects every name of `$requireACP`)
 *  - require-acp:   create/update/delete cannot be called through AJAX from the frontend: in `$requireACP` unless the
 *                   action denies them anyway (no `$permissions*` and no own `validate*()`). `$requireACP` only blocks AJAX
 *                   requests from outside the ACP (validateAction()); forms, commands, cronjobs and RPC endpoints still run
 *                   the method, also in the frontend (core: UserAction `create` from RegisterForm). An endpoint that calls
 *                   validateX() directly bypasses `$requireACP`: for an ACP-only method the endpoint or validateX() has to
 *                   check `admin.general.canUseAcp` or an administrator permission itself (not checked here, the rule
 *                   cannot tell ACP-only methods from frontend methods)
 *  - acp-menu:      ACP menu items are registered with the ItemCollecting event (listener in the bootstrap), acpMenu.xml
 *                   only holds <delete> entries for removing old items
 *  - cache-builder: caches are eager (AbstractEagerCache) or tolerant (AbstractTolerantCache) caches of 6.2, not
 *                   AbstractCacheBuilder/AbstractLegacyCacheBuilder
 *  - legacy-form:   forms use the form builder (AbstractFormBuilderForm), not AbstractForm/AbstractAcpForm
 *  - listener-instanceof: event listener classes check `$eventObj` with instanceof
 *  - legacy-listener:     eventListener.xml imports legacy listeners, use PSR-14 events and listeners registered in the
 *                         bootstrap (keep only <delete>)
 *  - legacy-ajax:   TypeScript, JavaScript and templates call DatabaseObjectActions (`WoltLabSuite/Core/Ajax`,
 *                   `dboAction`, `className: "…\data\…Action"`, `WCF.Action.*`, `ajax-proxy`), use RPC endpoints
 *  - core-table-write: no INSERT/UPDATE/DELETE/REPLACE on the tables of a base plugin (`coreTables` in
 *                   tests/lint/config.php), write through the commands of the base plugin; reading and the update
 *                   scripts in files/acp stay allowed
 *  - open-redirect:  a RedirectResponse to a request value (`$_GET/$_POST/$_REQUEST`, `returnURL`) needs an
 *                   ApplicationHandler::isInternalURL() check in the same file
 *  - grid-view-access: a grid view with an ID constructor parameter (comes from the client when rows are reloaded) whose
 *                   isAccessible() only checks `userID > 0`, it has to check access to the parent object
 *  - link-application: `{link controller='X'}` in templates/ of an application without `application=` for a controller
 *                   (Page/Form/Action) of the plugin itself, it resolves against wcf
 *  - sql-concat:    `->query()`/`->prepare()` with a string built by concatenation (literal `.` $var or a variable that
 *                   was built with `.=`), instead of `->prepareStatement()` with bound parameters. Same risk as classic
 *                   SQL injection regardless of where the value comes from (Marcel 21.10.2026, Sitzung).
 *
 * Rules that need the types of PHPStan are PHPStan rules (tests/phpstan): getPermission() compared with true/false
 * (yourecom.permissionStrict), legacy actions that change data without the token (yourecom.secureAction), endpoints that
 * bypass $requireACP (yourecom.endpointRequireAcp).
 *
 * Known findings that cannot be changed yet are listed in tests/lint/config.php (`architectureKnown`: relative file
 * => list of rules); entries that no longer match make the check fail, so the list can only shrink.
 *
 * Usage: php tests/lint/check-architecture.php
 */

namespace yourecom\standard;

require __DIR__ . '/lib.php';

const LEGACY_LIST_PAGES = 'SortablePage|MultipleLinkPage|AbstractListPage';

/** calls of DatabaseObjectActions from TypeScript, JavaScript and templates (the AJAX API before RPC endpoints) */
const LEGACY_AJAX = [
    '~[\x22\x27]WoltLabSuite/Core/Ajax(?:/DboAction)?[\x22\x27]~' => 'WoltLabSuite/Core/Ajax',
    '~\b(?:define|require)\s*\(\s*\[[^\]]*[\x22\x27]Ajax[\x22\x27]~' => 'AMD module Ajax',
    '~\bdboAction\b~' => 'dboAction',
    '~[\x22\x27][a-z]\w*(?:\x5C{1,2}\w+)*\x5C{1,2}data\x5C{1,2}(?:\w+\x5C{1,2})*\w+Action[\x22\x27]~' => 'class name of a DatabaseObjectAction',
    '~\bWCF\.Action\.\w+~' => 'WCF.Action',
    '~ajax-proxy~' => 'ajax-proxy',
];

/** @var array<string, list<string>> $findings relative file => rules */
$findings = [];
/** @var array<string, string> $messages "file|rule" => message */
$messages = [];

$report = static function (string $file, string $rule, string $message) use (&$findings, &$messages): void {
    $findings[$file][] = $rule;
    $messages["{$file}|{$rule}"] ??= "{$file}: [{$rule}] {$message}";
};

foreach (files(['files', 'files_wcf'], ['.php']) as $relative => $path) {
    $source = (string)\file_get_contents($path);

    if (\preg_match('~\bextends\s+\\\\?(?:wcf\\\\page\\\\)?(' . LEGACY_LIST_PAGES . ')\b~', $source, $match)) {
        $report($relative, 'list-page', "extends {$match[1]}, use AbstractGridViewPage (ACP) or AbstractListViewPage with a grid/list view");
    }

    if (\preg_match('~\bextends\s+\\\\?(?:wcf\\\\system\\\\clipboard\\\\action\\\\)?AbstractClipboardAction\b~', $source)) {
        $report($relative, 'clipboard', 'clipboard action, use bulk interactions of the grid/list view');
    }

    if (\preg_match('~\bextends\s+\\\\?(?:wcf\\\\system\\\\cache\\\\builder\\\\)?(?:AbstractCacheBuilder|AbstractLegacyCacheBuilder|AbstractSortedUserCacheBuilder)\b~', $source)) {
        $report($relative, 'cache-builder', 'cache builder, use an eager (AbstractEagerCache) or tolerant (AbstractTolerantCache) cache');
    }

    if (\preg_match('~\bextends\s+\\\\?(?:wcf\\\\(?:acp\\\\)?form\\\\)?(AbstractForm|AbstractAcpForm|AbstractCaptchaForm)\b~', $source, $match)) {
        $report($relative, 'legacy-form', "extends {$match[1]}, use the form builder (AbstractFormBuilderForm)");
    }

    if (
        \preg_match('~\bimplements\b[^{]*\bIParameterizedEventListener\b|\bextends\s+\\\\?(?:wcf\\\\system\\\\event\\\\listener\\\\)?AbstractEventListener\b~', $source)
        && !\str_contains($source, 'instanceof')
    ) {
        $report($relative, 'listener-instanceof', 'event listener without an instanceof check of $eventObj');
    }

    if (
        \preg_match('~new\s+RedirectResponse\(\s*(?:\$_(?:GET|POST|REQUEST)\b|\$this->(?:returnURL|redirectURL|url)\b)~', $source)
        && !\str_contains($source, 'isInternalURL')
    ) {
        $report($relative, 'open-redirect', 'redirect to a request value without ApplicationHandler::isInternalURL()');
    }
    // an RPC request is never an ACP request: validateAction() rejects every name in $requireACP there
    if (\str_contains($relative, '/system/endpoint/') && \preg_match('~->validateAction\(\)~', $source)) {
        $report($relative, 'endpoint-validate-action', 'RPC endpoint calls validateAction(), call the check directly (e.g. validateDelete()) or check in the endpoint/command');
    }

    // ->query()/->prepare() (not the parameter-bound ->prepareStatement() wrapper) with a string built by
    // concatenation: same risk as classic SQL injection, whether or not the value comes from user input
    \preg_match_all('~\$(\w+)\s*(?:\.=|=\s*(?:\$\1\s*)?\.)~', $source, $concatMatches);
    $concatenatedVars = \array_unique($concatMatches[1]);
    \preg_match_all('~->(query|prepare)\s*\(\s*([^)]*)~', $source, $sqlCalls, \PREG_SET_ORDER);
    foreach ($sqlCalls as $call) {
        [, $method, $argument] = $call;
        if (\str_contains($argument, '.')) {
            $report($relative, 'sql-concat', "->{$method}() with inline string concatenation, use ->prepareStatement() with bound parameters");
            continue;
        }
        if (\preg_match('~^\$(\w+)~', \trim($argument), $variable) && \in_array($variable[1], $concatenatedVars, true)) {
            $report($relative, 'sql-concat', "->{$method}(\${$variable[1]}) where \${$variable[1]} is built by concatenation, use ->prepareStatement() with bound parameters");
        }
    }

    // rows are reloaded through core/grid-views/rows with the grid view parameters of the client: a grid view that takes
    // such a parameter (an ID of a parent object) must check access to that object, not only that someone is logged in
    if (
        \preg_match('~\bextends\s+\\\\?(?:wcf\\\\system\\\\gridView\\\\)?AbstractGridView\b~', $source)
        && \preg_match('~function\s+__construct\s*\([^)]*\$\w+ID\b~', $source)
        && \preg_match('~function\s+isAccessible\s*\(\s*\)[^{]*\{\s*return\s+WCF::getUser\(\)->userID\s*>\s*0\s*;~', $source)
    ) {
        $report($relative, 'grid-view-access', 'grid view with an ID parameter whose isAccessible() only checks the login, check access to the parent object (e.g. canEdit())');
    }

    if (!\preg_match('~\bextends\s+\\\\?(?:wcf\\\\data\\\\)?AbstractDatabaseObjectAction\b~', $source)) {
        continue;
    }

    if (\preg_match('~\bIToggleAction\b|\bTDatabaseObjectToggle\b~', $source)) {
        $report($relative, 'toggle-action', 'IToggleAction/TDatabaseObjectToggle, use enable/disable RPC endpoints with a command');
    }

    \preg_match_all('~public\s+function\s+(validate\w+)\s*\(~', $source, $matches);
    $custom = \array_diff($matches[1], ['validateCreate', 'validateUpdate', 'validateDelete']);
    if ($custom !== []) {
        $report($relative, 'ajax-method', 'AJAX entry points ' . \implode(', ', $custom) . '(), use RPC endpoints');
    }

    // AJAX (AJAXProxyAction -> validateAction()) reaches create/update/delete from the frontend only if the name is not
    // in $requireACP and the action can pass validate*(): with $permissions* set or an own validate*() method
    // (the core's validate*() deny everything without $permissions*); RPC endpoints never go through this check
    $requireAcp = [];
    if (\preg_match('~\$requireACP\s*=\s*\[([^\]]*)\]~', $source, $match)) {
        \preg_match_all('~[\'"](\w+)[\'"]~', $match[1], $names);
        $requireAcp = $names[1];
    }
    $reachable = [];
    foreach (['create', 'update', 'delete'] as $name) {
        if (\in_array($name, $requireAcp, true)) {
            continue;
        }
        $permissions = '~\$permissions' . \ucfirst($name) . '\s*=\s*\[\s*[\'"]~';
        $validator = '~function\s+validate' . \ucfirst($name) . '\s*\(~';
        if (\preg_match($permissions, $source) || \preg_match($validator, $source)) {
            $reachable[] = $name;
        }
    }
    if ($reachable !== []) {
        $report($relative, 'require-acp', 'the frontend can call ' . \implode(', ', $reachable) . ' through AJAX, add them to $requireACP (forms and endpoints with executeAction() keep working; an endpoint that calls validate' . \ucfirst($reachable[0]) . '() directly bypasses $requireACP and has to check the ACP or an administrator permission itself if the method is ACP-only)');
    }
}

foreach (files(templateDirectories(), ['.tpl']) as $relative => $path) {
    $source = (string)\file_get_contents($path);

    if (\preg_match('~\{objectAction\b|jsObjectActionContainer~', $source)) {
        $report($relative, 'object-action', '{objectAction}/jsObjectActionContainer, use interactions (quick interactions for toggles)');
    }

    if (\preg_match('~jsClipboard(?:Container|Item|Editor|MarkAll)|Controller/Clipboard~', $source)) {
        $report($relative, 'clipboard', 'clipboard markup, use bulk interactions of the grid/list view');
    }

    // without `application` a link resolves against wcf: controllers of the plugin's own application need it
    if (\str_starts_with($relative, 'templates/') && (applications()['templates'] ?? 'wcf') !== 'wcf') {
        \preg_match_all('~\{(?:link|anchor)\s+([^}]*)\}~', $source, $tags);
        foreach ($tags[1] as $arguments) {
            if (
                \preg_match('~\bcontroller=[\x22\x27](\w+)[\x22\x27]~', $arguments, $controller)
                && !\str_contains($arguments, 'application=')
                && (\glob(ROOT . '/files/lib/*/' . $controller[1] . '{Page,Form,Action}.class.php', \GLOB_BRACE) ?: []) !== []
            ) {
                $report($relative, 'link-application', "{link controller='{$controller[1]}'} without application, the controller belongs to this plugin's application");
            }
        }
    }
}

$legacyAjax = static function (string $relative, string $source) use ($report): void {
    $found = [];
    foreach (LEGACY_AJAX as $pattern => $label) {
        if (\preg_match($pattern, $source)) {
            $found[] = $label;
        }
    }
    if ($found !== []) {
        $report($relative, 'legacy-ajax', 'legacy AJAX (' . \implode(', ', $found) . '), use RPC endpoints');
    }
};

foreach (files(['ts'], ['.ts']) as $relative => $path) {
    if (!\str_ends_with($relative, '.test.ts') && !\str_ends_with($relative, '.d.ts')) {
        $legacyAjax($relative, (string)\file_get_contents($path));
    }
}

// hand-written JavaScript; the committed build of ts/ is checked through its sources
$jsBuildDir = config()['jsBuildDir'];
foreach (files(['files', 'files_wcf'], ['.js']) as $relative => $path) {
    if (\str_ends_with($relative, '.min.js') || ($jsBuildDir !== null && \str_starts_with($relative, \rtrim($jsBuildDir, '/') . '/'))) {
        continue;
    }
    $legacyAjax($relative, (string)\file_get_contents($path));
}

// templates with {objectAction}/jsObjectActionContainer are already reported as object-action
foreach (files(templateDirectories(), ['.tpl']) as $relative => $path) {
    if (!\in_array('object-action', $findings[$relative] ?? [], true)) {
        $legacyAjax($relative, (string)\file_get_contents($path));
    }
}

$clipboardXml = ROOT . '/clipboardAction.xml';
if (\is_file($clipboardXml) && \str_contains((string)\file_get_contents($clipboardXml), '<import>')) {
    $report('clipboardAction.xml', 'clipboard', 'installs clipboard actions, use bulk interactions (keep only <delete>)');
}

$coreTables = config()['coreTables'];
if ($coreTables !== []) {
    $tables = \implode('|', \array_map(static fn (string $table): string => \preg_quote($table, '~'), $coreTables));
    $pattern = '~\b(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+(' . $tables . ')\b~i';

    // update scripts in files/acp migrate data and may write the tables directly
    foreach (files(['files/lib', 'files_wcf/lib'], ['.php']) as $relative => $path) {
        if (\preg_match($pattern, (string)\file_get_contents($path), $match)) {
            $report($relative, 'core-table-write', "writes {$match[1]} directly, use the commands of the base plugin");
        }
    }
}

$eventListenerXml = ROOT . '/eventListener.xml';
if (\is_file($eventListenerXml) && \str_contains((string)\file_get_contents($eventListenerXml), '<import>')) {
    $report('eventListener.xml', 'legacy-listener', 'imports event listeners, use PSR-14 events and listeners registered in the bootstrap (keep only <delete>)');
}

$acpMenuXml = ROOT . '/acpMenu.xml';
if (\is_file($acpMenuXml) && \str_contains((string)\file_get_contents($acpMenuXml), '<import>')) {
    $report('acpMenu.xml', 'acp-menu', 'imports ACP menu items, register them with the ItemCollecting event (keep only <delete>)');
}

$known = config()['architectureKnown'];
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
            $errors[] = "tests/lint/config.php: architectureKnown {$file} [{$rule}] no longer applies, remove it";
        }
    }
}

finish($errors, \count($findings) === 0 ? 'no legacy patterns' : \count($known) . ' known');
