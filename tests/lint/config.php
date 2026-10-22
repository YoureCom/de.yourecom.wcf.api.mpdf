<?php

/**
 * Plugin specific settings of the YoureCom Standard checks (tests/lint/*).
 */
return [
    // statically used language variables with these prefixes must exist in language/*.xml
    'languagePrefixes' => [],
    // variables with these prefixes that are defined elsewhere (keep this list short)
    'languageIgnore' => [],
    // gaps of the WoltLab XSDs: file name => parts of the validation message (the list can only shrink)
    'xsdKnown' => [],
    // legacy patterns that cannot be changed yet: relative file => rules of check-architecture.php (the list can only shrink)
    'architectureKnown' => [],
    // comment findings of the standard that are not cleaned up yet: relative file => rules of check-comments.php (the list can only shrink)
    'commentsKnown' => [
    ],
    // committed build of ts/ (the outDir of tsconfig.test.json), null without TypeScript
    'jsBuildDir' => null,
];
