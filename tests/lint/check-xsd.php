<?php

/**
 * YoureCom Standard: validates the PIP and language files against the XSDs of WoltLab Suite 6.2.
 * Managed by _dev/standard (apply.php), do not edit here.
 *
 * Every file has to reference https://www.woltlab.com/XSD/6.0/<pip>.xsd; own PIPs of the YoureCom plugins reference
 * https://www.yourecom.de/XSD/6.2/<pip>.xsd (files/XSD/6.2 of de.yourecom.website, environment variable YOURECOM_XSD).
 * The XSDs of WoltLab come from the WCF repository
 * (directory `XSD`): environment variable WCF_XSD, locally default `../var/wcf/XSD` (cloned by _dev/standard/check.sh).
 * Gaps of the XSDs (elements the core accepts but the XSD does not know) are listed in tests/lint/config.php
 * (`xsdKnown`); entries that no longer match make the check fail, so the list can only shrink.
 *
 * Usage: php tests/lint/check-xsd.php
 */

namespace yourecom\standard;

require __DIR__ . '/lib.php';

$xsdDirectory = \getenv('WCF_XSD');
if ($xsdDirectory === false || $xsdDirectory === '') {
    $xsdDirectory = ROOT . '/../var/wcf/XSD';
}
if (!\is_dir($xsdDirectory)) {
    fail(["The XSD directory does not exist: {$xsdDirectory} (set WCF_XSD)"]);
}

// XSDs of the own PIPs of the YoureCom plugins: environment variable YOURECOM_XSD, locally the folder of the website plugin
$yourecomXsdDirectory = \getenv('YOURECOM_XSD');
if ($yourecomXsdDirectory === false || $yourecomXsdDirectory === '') {
    $yourecomXsdDirectory = ROOT . '/../de.yourecom.website/files/XSD/6.2';
}

// gaps of the XSDs of the WCF repository: the core accepts and uses these elements and attributes in its own packages
const CORE_GAPS = [
    'objectType.xml' => ['defaultpermission', '}page'],
    'package.xml' => ["'languagecode'"],
    'templateListener.xml' => ['templatecode'],
    'userGroupOption.xml' => ["is not a valid value of the atomic type 'xs:unsignedInt'"],
];

$known = config()['xsdKnown'];
$matched = [];
$errors = [];
$checked = 0;

\libxml_use_internal_errors(true);
foreach (\array_merge(\glob(ROOT . '/*.xml') ?: [], \glob(ROOT . '/language/*.xml') ?: []) as $file) {
    $name = \basename($file);
    if (\str_starts_with($name, 'phpunit')) {
        continue;
    }

    $document = new \DOMDocument();
    if (!$document->load($file)) {
        $errors[] = "{$name}: not well-formed";
        continue;
    }

    $schemaLocation = $document->documentElement?->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'schemaLocation') ?? '';
    if (\preg_match('~^http://www\.woltlab\.com\s+https://www\.yourecom\.de/XSD/6\.2/(\w+\.xsd)$~', \trim($schemaLocation), $match)) {
        // PIPs of the YoureCom plugins have no WoltLab XSD, theirs live in de.yourecom.website (files/XSD/6.2)
        $schema = $yourecomXsdDirectory . '/' . $match[1];
    } elseif (\preg_match('~^http://www\.woltlab\.com\s+https://www\.woltlab\.com/XSD/6\.0/(\w+\.xsd)$~', \trim($schemaLocation), $match)) {
        $schema = $xsdDirectory . '/' . $match[1];
    } else {
        $errors[] = "{$name}: xsi:schemaLocation must be \"http://www.woltlab.com https://www.woltlab.com/XSD/6.0/<pip>.xsd\" (own PIPs: \"http://www.woltlab.com https://www.yourecom.de/XSD/6.2/<pip>.xsd\")";
        continue;
    }
    if (!\is_file($schema)) {
        $errors[] = "{$name}: schema {$match[1]} not found";
        continue;
    }

    $checked++;
    \libxml_clear_errors();
    if ($document->schemaValidate($schema)) {
        continue;
    }

    foreach (\libxml_get_errors() as $error) {
        $message = \trim($error->message);
        foreach (CORE_GAPS[$name] ?? [] as $part) {
            if (\str_contains($message, $part)) {
                continue 2;
            }
        }
        foreach ($known[$name] ?? [] as $part) {
            if (\str_contains($message, $part)) {
                $matched[$name][$part] = true;
                continue 2;
            }
        }
        $errors[] = \sprintf('%s:%d: %s', $name, $error->line, $message);
    }
}

foreach ($known as $name => $parts) {
    foreach ($parts as $part) {
        if (!isset($matched[$name][$part])) {
            $errors[] = "{$name}: the known finding '{$part}' does not occur any more, remove it from tests/lint/config.php";
        }
    }
}

finish($errors, "{$checked} files");
