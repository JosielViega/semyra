<?php

declare(strict_types=1);

namespace Semyra\BridgeWorker;

use RuntimeException;

final class ControlException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Control plane request failed.');
    }
}
