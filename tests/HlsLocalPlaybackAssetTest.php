<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class HlsLocalPlaybackAssetTest extends TestCase
{
    public function testOfficialStaticBundleAndLicenseArePresentWithoutCdnDependency(): void
    {
        $root = dirname(__DIR__);
        $bundle = $root . '/public/assets/vendor/hls/hls.min.js';
        $license = $root . '/public/assets/vendor/hls/LICENSE';

        self::assertFileExists($bundle);
        self::assertFileExists($license);
        self::assertGreaterThan(500_000, filesize($bundle));
        self::assertStringContainsString('Apache License, Version 2.0', (string) file_get_contents($license));

        $view = (string) file_get_contents($root . '/resources/views/pages/desktop-iptv.php');
        self::assertStringContainsString('/assets/vendor/hls/hls.min.js?v=1.7.3', $view);
        self::assertStringNotContainsString('cdn.jsdelivr.net', $view);
        self::assertStringNotContainsString('unpkg.com', $view);
    }
}
