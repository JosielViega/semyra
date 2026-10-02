<?php

declare(strict_types=1);

namespace App\Services;

final class MediaBridgeJobState
{
    public const DESIRED = ['running', 'stopped'];
    public const STATUSES = ['pending', 'claimed', 'starting', 'running', 'stopping', 'stopped', 'failed'];
    public const WORKER_HEARTBEAT = ['starting', 'running'];
    public const WORKER_REPORT = ['running', 'failed', 'stopped'];

    public static function validDesired(string $state): bool
    {
        return in_array($state, self::DESIRED, true);
    }

    public static function validStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }
}
