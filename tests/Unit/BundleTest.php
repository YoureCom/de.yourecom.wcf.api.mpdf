<?php

namespace yourecom\tests\mpdf;

use PHPUnit\Framework\TestCase;

final class BundleTest extends TestCase
{
    private const BUNDLE = __DIR__ . '/../../files/lib/system/api/mpdf';

    public function testComposerJsonRequiresMpdf(): void
    {
        $composer = \json_decode((string)\file_get_contents(self::BUNDLE . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($composer);
        self::assertIsArray($composer['require'] ?? null);
        self::assertArrayHasKey('mpdf/mpdf', $composer['require']);
    }

    public function testAutoloadLoadsTheComposerAutoloader(): void
    {
        $autoload = (string)\file_get_contents(self::BUNDLE . '/autoload.php');

        self::assertStringContainsString("/vendor/autoload.php", $autoload);
    }

    public function testLockFileIsCommitted(): void
    {
        self::assertFileExists(self::BUNDLE . '/composer.lock');
    }
}
