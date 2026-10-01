<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testJsonAcceptsExtraHeadersWithoutChangingExistingDefaults(): void
    {
        $response = Response::json(['ok' => true], 201, [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        self::assertSame(201, $response->status());
        self::assertSame('{"ok":true}', $response->body());
        self::assertSame('application/json; charset=UTF-8', $response->headers()['Content-Type']);
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
        self::assertSame(['Content-Type' => 'application/json; charset=UTF-8'], Response::json([])->headers());
    }
}
