<?php

declare(strict_types=1);

namespace App\Core;

final class Logger
{
    public function __construct(private readonly string $logDirectory)
    {
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        if (!is_dir($this->logDirectory) && !mkdir($this->logDirectory, 0775, true) && !is_dir($this->logDirectory)) {
            error_log($message);
            return;
        }

        $entry = sprintf(
            "[%s] %s: %s %s%s",
            date(DATE_ATOM),
            $level,
            str_replace(["\r", "\n"], ' ', $message),
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            PHP_EOL,
        );

        error_log($entry, 3, $this->logDirectory . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log');
    }
}
