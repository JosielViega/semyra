<?php

declare(strict_types=1);

namespace Tests;

use App\Services\YouTubeUrlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YouTubeUrlParserTest extends TestCase
{
    #[DataProvider('validUrlProvider')]
    public function testParsesSupportedYouTubeUrls(string $url, string $expectedId): void
    {
        self::assertSame($expectedId, (new YouTubeUrlParser())->parse($url));
    }

    public static function validUrlProvider(): array
    {
        return [
            'youtube watch' => ['https://youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'www watch with extra query' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10', 'dQw4w9WgXcQ'],
            'mobile watch' => ['https://m.youtube.com/watch?v=AbCdEf123-_', 'AbCdEf123-_'],
            'short host' => ['https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'short host with query' => ['https://youtu.be/dQw4w9WgXcQ?si=example', 'dQw4w9WgXcQ'],
            'youtube live' => ['https://youtube.com/live/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'www live with query' => ['https://www.youtube.com/live/dQw4w9WgXcQ?feature=share', 'dQw4w9WgXcQ'],
            'mobile live over http' => ['http://m.youtube.com/live/AbCdEf123-_', 'AbCdEf123-_'],
        ];
    }

    #[DataProvider('invalidUrlProvider')]
    public function testRejectsUnsupportedOrMalformedUrls(string $url): void
    {
        self::assertNull((new YouTubeUrlParser())->parse($url));
    }

    public static function invalidUrlProvider(): array
    {
        return [
            'empty' => [''],
            'malformed' => ['not a URL'],
            'other host' => ['https://example.com/watch?v=dQw4w9WgXcQ'],
            'host suffix attack' => ['https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ'],
            'similar host' => ['https://notyoutube.com/watch?v=dQw4w9WgXcQ'],
            'watch without id' => ['https://youtube.com/watch'],
            'watch with empty id' => ['https://youtube.com/watch?v='],
            'playlist' => ['https://youtube.com/playlist?list=PL1234567890'],
            'shorts' => ['https://youtube.com/shorts/dQw4w9WgXcQ'],
            'embed' => ['https://youtube.com/embed/dQw4w9WgXcQ'],
            'channel' => ['https://youtube.com/channel/UC123456789'],
            'id too short' => ['https://youtu.be/short'],
            'id too long' => ['https://youtu.be/dQw4w9WgXcQx'],
            'invalid id character' => ['https://youtu.be/dQw4w9WgXc!'],
            'extra path on short host' => ['https://youtu.be/dQw4w9WgXcQ/more'],
            'extra live path' => ['https://youtube.com/live/dQw4w9WgXcQ/more'],
            'unsupported scheme' => ['ftp://youtube.com/watch?v=dQw4w9WgXcQ'],
            'credentials in URL' => ['https://user@youtube.com/watch?v=dQw4w9WgXcQ'],
            'custom port' => ['https://youtube.com:8443/watch?v=dQw4w9WgXcQ'],
        ];
    }
}
