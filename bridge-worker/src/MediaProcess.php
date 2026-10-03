<?php

declare(strict_types=1);

namespace Semyra\BridgeWorker;

interface MediaProcess
{
    public function start(string $sourceUrl, string $whipEndpoint, array $job): void;
    public function poll(): string;
    public function errorCode(): string;
    public function stop(): void;
}

interface MediaProcessFactory
{
    public function create(Watchdog $watchdog): MediaProcess;
}
