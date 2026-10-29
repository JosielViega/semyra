<?php

declare(strict_types=1);

namespace Tests;

use App\Services\PremiumAccess;
use PHPUnit\Framework\TestCase;

final class PremiumAccessTest extends TestCase
{
    public function testOnlyExplicitPremiumEntitlementAllowsIptvPublishing(): void
    {
        $access = new PremiumAccess();

        self::assertTrue($access->canPublishIptv(['is_premium' => true]));
        self::assertFalse($access->canPublishIptv(['is_premium' => false]));
        self::assertFalse($access->canPublishIptv([]));

        self::assertTrue($access->canShareScreen(['is_premium' => true]));
        self::assertFalse($access->canShareScreen(['is_premium' => false]));
        self::assertFalse($access->canShareScreen([]));
    }
}
