<?php

declare(strict_types=1);

namespace Semyra\BridgeWorker;

interface StopRequest
{
    public function requested(): bool;
}

final class NullStopRequest implements StopRequest
{
    public function requested(): bool
    {
        return false;
    }
}
