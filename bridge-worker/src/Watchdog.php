<?php

declare(strict_types=1);

namespace Semyra\BridgeWorker;

final class Watchdog
{
    private string $path;

    public function __construct(string $runtimePath, string $workerId, private readonly Clock $clock)
    {
        if (!is_dir($runtimePath) && !mkdir($runtimePath, 0700, true) && !is_dir($runtimePath)) {
            throw new WorkerException('runtime_unavailable');
        }
        $this->path = $runtimePath . DIRECTORY_SEPARATOR . 'watchdog-' . $workerId;
    }

    public function touch(): void
    {
        if (@file_put_contents($this->path, (string) (int) $this->clock->now(), LOCK_EX) === false) {
            throw new WorkerException('runtime_unavailable');
        }
    }

    public function fresh(int $maximumAgeSeconds): bool
    {
        $timestamp = is_file($this->path) ? (int) @file_get_contents($this->path) : 0;
        return $timestamp > 0 && $this->clock->now() - $timestamp <= $maximumAgeSeconds;
    }

    public function remove(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function path(): string
    {
        return $this->path;
    }
}
