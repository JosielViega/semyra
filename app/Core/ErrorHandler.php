<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

final class ErrorHandler
{
    public function __construct(
        private readonly Logger $logger,
        private readonly bool $debug,
    ) {
    }

    public function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(fn (Throwable $exception) => $this->render($exception));
    }

    private function render(Throwable $exception): never
    {
        $reference = bin2hex(random_bytes(6));
        $this->logger->error($exception->getMessage(), [
            'reference' => $reference,
            'exception' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $message = $this->debug
            ? '<h1>Application error</h1><pre>' . e((string) $exception) . '</pre>'
            : '<h1>Unexpected error</h1><p>Please try again later. Reference: ' . e($reference) . '</p>';

        Response::html($message, 500)->send();
    }
}
