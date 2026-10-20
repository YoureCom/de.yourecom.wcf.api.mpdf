<?php

/**
 * YoureCom Standard: registers the PHPStan rules of the standard. Managed by _dev/standard (apply.php), do not edit
 * here. Included by phpstan.standard.neon: PHPStan builds its container before it loads bootstrapFiles, so the rule
 * classes are loaded here, while the configuration is read.
 */

$rules = [
    'OverrideOrDocblockRule',
    'PermissionStrictRule',
    'SecureActionRule',
    'EndpointRequireAcpRule',
];

foreach ($rules as $rule) {
    require_once __DIR__ . '/' . $rule . '.php';
}

return [
    'services' => \array_map(
        static fn (string $rule): array => ['class' => 'yourecom\standard\phpstan\\' . $rule, 'tags' => ['phpstan.rules.rule']],
        $rules,
    ),
];
