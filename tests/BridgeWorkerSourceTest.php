<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Semyra\BridgeWorker\MpegTsValidator;
use Semyra\BridgeWorker\InitialMpegTsBuffer;
use Semyra\BridgeWorker\SourceCatalog;
use Semyra\BridgeWorker\WorkerException;

require_once __DIR__ . '/../bridge-worker/bootstrap.php';

final class BridgeWorkerSourceTest extends TestCase
{
    public function testResolvesExactPrivateReference(): void
    {
        $path = $this->catalog(['channel:one' => ['type' => 'http_mpegts', 'url' => 'https://media.example.invalid/live.ts']]);
        try {
            self::assertSame('https://media.example.invalid/live.ts', (new SourceCatalog($path))->resolve('channel:one'));
        } finally {
            @unlink($path);
        }
    }

    public function testAllowsHttpSourceRequiredByCurrentProviderBoundary(): void
    {
        $path = $this->catalog(['channel:http' => ['type' => 'http_mpegts', 'url' => 'http://127.0.0.1/live.ts']]);
        try {
            self::assertSame('http://127.0.0.1/live.ts', (new SourceCatalog($path))->resolve('channel:http'));
        } finally {
            @unlink($path);
        }
    }

    public function testDoesNotResolveUnknownOrPartialReference(): void
    {
        $path = $this->catalog(['channel:one' => ['type' => 'http_mpegts', 'url' => 'https://media.example.invalid/live.ts']]);
        try {
            $this->expectException(WorkerException::class);
            (new SourceCatalog($path))->resolve('channel');
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsNonHttpSource(): void
    {
        $path = $this->catalog(['bad' => ['type' => 'http_mpegts', 'url' => 'file:///private/video.ts']]);
        try {
            $this->expectException(WorkerException::class);
            new SourceCatalog($path);
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsMalformedCatalogWithoutLeakingItsUrl(): void
    {
        $privateUrl = 'https://private.example.invalid/user:password/stream?id=secret';
        $path = tempnam(sys_get_temp_dir(), 'semyra-bad-source-');
        file_put_contents($path, '{broken ' . $privateUrl);
        try {
            new SourceCatalog($path);
            self::fail('Malformed catalog was accepted.');
        } catch (WorkerException $exception) {
            self::assertSame('catalog_invalid', $exception->errorCode);
            self::assertStringNotContainsString($privateUrl, $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function testDetectsMpegTsSyncAndRejectsGarbage(): void
    {
        $packet = "\x47" . str_repeat("\0", 187);
        self::assertSame(0, MpegTsValidator::syncOffset($packet . $packet . $packet));
        self::assertSame(3, MpegTsValidator::syncOffset('abc' . $packet . $packet . $packet));
        self::assertNull(MpegTsValidator::syncOffset(str_repeat('x', 600)));
    }

    public function testInitialBufferIsPreservedAfterValidation(): void
    {
        $packet = "\x47" . str_repeat("\0", 187);
        $input = $packet . $packet . $packet . $packet . $packet . $packet;
        $buffer = new InitialMpegTsBuffer();

        self::assertNull($buffer->push(substr($input, 0, 500)));
        self::assertSame($input, $buffer->push(substr($input, 500)));
        self::assertSame('next', $buffer->push('next'));
    }

    #[DataProvider('rejectedPayloadProvider')]
    public function testRejectsCommonNonMpegTsPayloads(string $payload): void
    {
        $this->expectException(WorkerException::class);
        (new InitialMpegTsBuffer())->push(str_pad($payload, 1024, 'x'));
    }

    public static function rejectedPayloadProvider(): array
    {
        return [
            'HTML' => ['<!doctype html><html>'],
            'HLS' => ["#EXTM3U\n#EXT-X-VERSION:3"],
            'MP4' => ["\0\0\0\x18ftypmp42"],
        ];
    }

    private function catalog(array $sources): string
    {
        $path = tempnam(sys_get_temp_dir(), 'semyra-source-');
        file_put_contents($path, json_encode(['version' => 1, 'sources' => $sources], JSON_THROW_ON_ERROR));
        return $path;
    }
}
