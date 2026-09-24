<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    public function __construct(private readonly bool $autoStart = true)
    {
    }

    public function start(array $options = []): void
    {
        if (!$this->autoStart || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (headers_sent($file, $line)) {
            throw new \RuntimeException("Cannot start session after output at {$file}:{$line}.");
        }

        if (!session_start($options)) {
            throw new \RuntimeException('Unable to start the session.');
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][$type][] = $message;
    }

    public function consumeFlash(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return is_array($messages) ? $messages : [];
    }
}
