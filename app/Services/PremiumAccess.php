<?php

declare(strict_types=1);

namespace App\Services;

final class PremiumAccess
{
    /** @param array<string, mixed> $user */
    public function canPublishIptv(array $user): bool
    {
        return ($user['is_premium'] ?? false) === true;
    }
}
