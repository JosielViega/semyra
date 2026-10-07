<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function __construct(
        private readonly array $queryParams = [],
        private readonly array $parsedBody = [],
        private readonly array $server = [],
        private readonly array $files = [],
        private readonly array $cookies = [],
        private readonly ?string $rawBody = null,
    ) {
    }

    public static function capture(): self
    {
        $rawBody = file_get_contents('php://input');

        return new self($_GET, $_POST, $_SERVER, $_FILES, $_COOKIE, is_string($rawBody) ? $rawBody : null);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->parsedBody[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $normalized = strtoupper(str_replace('-', '_', trim($name)));
        if ($normalized === '') {
            return $default;
        }

        $serverKey = in_array($normalized, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)
            ? $normalized
            : 'HTTP_' . $normalized;
        $value = $this->server[$serverKey] ?? null;

        return is_string($value) ? $value : $default;
    }

    /** @return null|array<string, mixed> */
    public function json(): ?array
    {
        if ($this->rawBody === null || trim($this->rawBody) === '') {
            return null;
        }
        try {
            $decoded = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : null;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function method(): string
    {
        $method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));

        if ($method === 'POST') {
            $override = strtoupper((string) ($this->parsedBody['_method'] ?? ''));
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        return $method;
    }

    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        $normalized = '/' . trim($path, '/');

        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') !== '' && ($this->server['HTTPS'] ?? '') !== 'off';
    }
}
