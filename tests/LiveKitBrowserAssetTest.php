<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class LiveKitBrowserAssetTest extends TestCase
{
    public function testPinnedLiveKitBrowserBundleAndLicenseArePresent(): void
    {
        $root = dirname(__DIR__) . '/public/assets/vendor/livekit';
        $bundle = $root . '/livekit-client.umd.js';
        $license = $root . '/LICENSE-livekit-client.txt';

        self::assertFileExists($bundle);
        self::assertSame(
            '7fa17e37af5e996d8a25f15a637dcc0620215bc01b394e5d209f726afe7dc04d',
            hash_file('sha256', $bundle),
        );
        self::assertFileExists($license);
        self::assertStringContainsString('Apache License', (string) file_get_contents($license));
    }
}
