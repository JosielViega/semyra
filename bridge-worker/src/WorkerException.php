<?php

declare(strict_types=1);

namespace Semyra\BridgeWorker;

use RuntimeException;

final class WorkerException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('Bridge worker operation failed.');
    }
}
