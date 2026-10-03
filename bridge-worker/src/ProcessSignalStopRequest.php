<?php

declare(strict_types=1);

namespace Semyra\BridgeWorker;

final class ProcessSignalStopRequest implements StopRequest
{
    private bool $requested = false;

    public function __construct()
    {
        if (!self::isSupported()) {
            throw new WorkerException('invalid_configuration');
        }

        pcntl_async_signals(true);
        $handler = function (int $_signal): void {
            $this->requested = true;
        };
        if (!pcntl_signal(SIGTERM, $handler) || !pcntl_signal(SIGINT, $handler)) {
            throw new WorkerException('invalid_configuration');
        }
    }

    public static function isSupported(): bool
    {
        return function_exists('pcntl_async_signals')
            && function_exists('pcntl_signal')
            && defined('SIGTERM')
            && defined('SIGINT');
    }

    public function requested(): bool
    {
        return $this->requested;
    }
}
