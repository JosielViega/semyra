<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = 200,
        private readonly array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new self($body, $status, array_merge(
            ['Content-Type' => 'application/json; charset=UTF-8'],
            $headers,
        ));
    }

    public static function redirect(string $location, int $status = 302): self
    {
        if (preg_match('/[\r\n]/', $location) === 1) {
            throw new \InvalidArgumentException('Invalid redirect location.');
        }

        return new self('', $status, ['Location' => $location]);
    }

    public function send(): never
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, strcasecmp($name, 'Set-Cookie') !== 0);
        }

        echo $this->body;
        exit;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function withHeader(string $name, string $value): self
    {
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name) !== 1
            || preg_match('/[\r\n]/', $value) === 1) {
            throw new \InvalidArgumentException('Invalid response header.');
        }

        return new self($this->body, $this->status, array_merge($this->headers, [$name => $value]));
    }
}
