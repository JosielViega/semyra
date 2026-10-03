<?php

declare(strict_types=1);

namespace Semyra\BridgeWorker;

interface Clock
{
    public function now(): float;
    public function sleep(float $seconds): void;
}

final class SystemClock implements Clock
{
    public function now(): float
    {
        return microtime(true);
    }

    public function sleep(float $seconds): void
    {
        usleep((int) max(0, $seconds * 1_000_000));
    }
}
