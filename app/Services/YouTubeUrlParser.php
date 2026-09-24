<?php

declare(strict_types=1);

namespace App\Services;

final class YouTubeUrlParser
{
    private const YOUTUBE_HOSTS = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
    ];

    public function parse(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
        ) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($host === 'youtu.be') {
            $segments = $this->pathSegments($path);
            return count($segments) === 1 ? $this->validId($segments[0]) : null;
        }

        if (!in_array($host, self::YOUTUBE_HOSTS, true)) {
            return null;
        }

        if ($path === '/watch') {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $videoId = $query['v'] ?? null;

            return is_string($videoId) ? $this->validId($videoId) : null;
        }

        $segments = $this->pathSegments($path);
        if (count($segments) === 2 && $segments[0] === 'live') {
            return $this->validId($segments[1]);
        }

        return null;
    }

    private function pathSegments(string $path): array
    {
        return array_values(array_filter(explode('/', trim($path, '/')), static fn (string $part): bool => $part !== ''));
    }

    private function validId(string $videoId): ?string
    {
        return preg_match('/^[A-Za-z0-9_-]{11}$/D', $videoId) === 1 ? $videoId : null;
    }
}
