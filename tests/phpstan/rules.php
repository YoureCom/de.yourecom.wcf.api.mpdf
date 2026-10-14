<?php

/**
 * YoureCom Standard: registers the PHPStan rules of the standard. Managed by _dev/standard (apply.php), do not edit
 * here. Included by phpstan.standard.neon: PHPStan builds its container before it loads bootstrapFiles, so the rule
 * classes are loaded here, while the configuration is read.
 */

require_once __DIR__ . '/OverrideOrDocblockRule.php';

return [
    'services' => [
        [
            'class' => \yourecom\standard\phpstan\OverrideOrDocblockRule::class,
            'tags' => ['phpstan.rules.rule'],
        ],
    ],
];
