<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class MediaBridgeIngressIdentity
{
    private const INSTANCE_PATTERN = '/^[a-f0-9]{32}$/';

    public static function name(string $instanceId, int $attempt): string
    {
        if (preg_match(self::INSTANCE_PATTERN, $instanceId) !== 1 || $attempt < 1) {
            throw new InvalidArgumentException('Media bridge ingress identity is invalid.');
        }

        return 'smy_b_' . $instanceId . '_a' . $attempt;
    }
}
