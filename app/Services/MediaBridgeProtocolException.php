<?php

declare(strict_types=1);

namespace App\Services;

final class MediaBridgeProtocolException extends \RuntimeException
{
    public function __construct(public readonly string $error, public readonly int $status)
    {
        parent::__construct($error);
    }
}
