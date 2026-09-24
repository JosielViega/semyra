<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Csrf;
use App\Core\Session;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testCreatesAndVerifiesSessionToken(): void
    {
        $csrf = new Csrf(new Session(false));
        $token = $csrf->token();

        self::assertSame(64, strlen($token));
        self::assertTrue($csrf->verify($token));
        self::assertFalse($csrf->verify('invalid'));
    }
}
