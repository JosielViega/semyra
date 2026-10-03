<?php

declare(strict_types=1);

namespace Semyra\BridgeWorker;

final class InitialMpegTsBuffer
{
    private string $buffer = '';
    private bool $validated = false;

    public function push(string $bytes): ?string
    {
        if ($this->validated) {
            return $bytes;
        }
        $this->buffer .= $bytes;
        if (strlen($this->buffer) < 1024) {
            return null;
        }
        if (MpegTsValidator::syncOffset($this->buffer) === null) {
            throw new WorkerException('source_invalid');
        }
        $this->validated = true;
        $payload = $this->buffer;
        $this->buffer = '';
        return $payload;
    }

    public function validated(): bool
    {
        return $this->validated;
    }
}
