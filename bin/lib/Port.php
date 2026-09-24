<?php

declare(strict_types=1);

namespace TemplateTools;

final class Port
{
    public const MIN = 1024;
    public const MAX = 65535;
    public const DEFAULT_START = 8010;
    public const DEFAULT_END = 8999;

    public static function isValid(mixed $port): bool
    {
        return filter_var($port, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => self::MIN, 'max_range' => self::MAX],
        ]) !== false;
    }

    public static function isAvailable(int $port, string $host = '127.0.0.1'): bool
    {
        if (!self::isValid($port)) {
            return false;
        }

        $message = '';
        set_error_handler(static function (int $severity, string $error) use (&$message): bool {
            $message = $error;
            return true;
        });

        try {
            $listener = fsockopen($host, $port, $errorCode, $message, 0.2);
        } finally {
            restore_error_handler();
        }

        if (is_resource($listener)) {
            fclose($listener);
            return false;
        }

        set_error_handler(static function (int $severity, string $error) use (&$message): bool {
            $message = $error;
            return true;
        });

        try {
            $socket = stream_socket_server("tcp://{$host}:{$port}", $errorCode, $message);
        } finally {
            restore_error_handler();
        }

        if (!is_resource($socket)) {
            return false;
        }

        fclose($socket);
        return true;
    }

    public static function findAvailable(
        int $start = self::DEFAULT_START,
        int $end = self::DEFAULT_END,
        ?callable $availabilityCheck = null,
    ): int {
        if ($start < self::MIN || $end > self::MAX || $start > $end) {
            throw new \InvalidArgumentException('Invalid port search range.');
        }

        $isAvailable = $availabilityCheck ?? self::isAvailable(...);
        for ($port = $start; $port <= $end; ++$port) {
            if ($isAvailable($port)) {
                return $port;
            }
        }

        throw new \RuntimeException("No available local port found between {$start} and {$end}.");
    }
}
