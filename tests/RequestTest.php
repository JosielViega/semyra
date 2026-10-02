<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testReadsApacheStyleHeadersCaseInsensitively(): void
    {
        $request = new Request(server: [
            'HTTP_X_SEMYRA_WORKER_TOKEN' => 'worker-token',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ]);

        self::assertSame('worker-token', $request->header('X-Semyra-Worker-Token'));
        self::assertSame('worker-token', $request->header('x-semyra-worker-token'));
        self::assertSame('application/x-www-form-urlencoded', $request->header('Content-Type'));
        self::assertSame('fallback', $request->header('X-Missing', 'fallback'));
    }

    public function testExistingRequestBehaviorRemainsUnchanged(): void
    {
        $request = new Request(
            queryParams: ['page' => '2'],
            parsedBody: ['_method' => 'PATCH', 'name' => 'Semyra'],
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/room/A?x=1', 'HTTPS' => 'on'],
            files: ['upload' => ['name' => 'safe.txt']],
        );

        self::assertSame('PATCH', $request->method());
        self::assertSame('/room/A', $request->path());
        self::assertSame('Semyra', $request->input('name'));
        self::assertSame('2', $request->query('page'));
        self::assertSame('safe.txt', $request->file('upload')['name']);
        self::assertTrue($request->isSecure());
    }
}
