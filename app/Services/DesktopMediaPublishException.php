<?php

declare(strict_types=1);

namespace App\Services;

final class DesktopMediaPublishException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($errorCode);
    }
}
