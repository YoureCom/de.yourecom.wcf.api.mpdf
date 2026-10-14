<?php

/**
 * Entry point of the PHPUnit unit suite. The unit tests run without a WoltLab installation, so only the plugin's own
 * classes are loaded: `<namespace>\foo\Bar` => `<dir>/lib/foo/Bar.class.php`.
 */

// the plugin's own vendor (CI) or the shared tools of the plugins folder (local)
require \is_file(__DIR__ . '/../vendor/autoload.php') ? __DIR__ . '/../vendor/autoload.php' : __DIR__ . '/../../_dev/tools/vendor/autoload.php';

\spl_autoload_register(static function (string $class): void {
    $directories = ['Composer\\' => 'files', 'Mpdf\\' => 'files', 'DeepCopy\\' => 'files', 'Psr\\' => 'files', 'setasign\\' => 'files'];

    foreach ($directories as $prefix => $directory) {
        if (!\str_starts_with($class, $prefix)) {
            continue;
        }

        $file = __DIR__ . '/../' . $directory . '/lib/' . \str_replace('\\', '/', \substr($class, \strlen($prefix))) . '.class.php';
        if (\is_file($file)) {
            require_once $file;

            return;
        }
    }
});
