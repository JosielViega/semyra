<?php

declare(strict_types=1);

namespace Tests;

use App\Services\MediaBridgeIngressIdentity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MediaBridgeIngressIdentityTest extends TestCase
{
    public function testNameIncludesAttemptGeneration(): void
    {
        $instance = str_repeat('a', 32);
        self::assertSame('smy_b_' . $instance . '_a1', MediaBridgeIngressIdentity::name($instance, 1));
        self::assertSame('smy_b_' . $instance . '_a2', MediaBridgeIngressIdentity::name($instance, 2));
    }

    public function testRejectsInvalidInstanceOrAttempt(): void
    {
        foreach ([['invalid', 1], [str_repeat('a', 32), 0], [str_repeat('a', 32), -1]] as [$instance, $attempt]) {
            try {
                MediaBridgeIngressIdentity::name($instance, $attempt);
                self::fail('Expected invalid ingress identity.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
