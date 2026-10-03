<?php

declare(strict_types=1);

namespace Semyra\BridgeWorker;

final class WorkerLock
{
    /** @var resource|null */
    private $handle = null;
    private ?string $path = null;

    public function acquire(string $runtimePath, string $workerId): void
    {
        if (!is_dir($runtimePath) && !mkdir($runtimePath, 0700, true) && !is_dir($runtimePath)) {
            throw new WorkerException('runtime_unavailable');
        }
        $this->path = $runtimePath . DIRECTORY_SEPARATOR . 'worker-' . $workerId . '.lock';
        $handle = @fopen($this->path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new WorkerException('worker_already_running');
        }
        $this->handle = $handle;
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
        if ($this->path !== null && is_file($this->path)) {
            @unlink($this->path);
        }
        $this->path = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
