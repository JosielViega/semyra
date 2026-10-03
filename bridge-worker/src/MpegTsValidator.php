<?php

declare(strict_types=1);

namespace Semyra\BridgeWorker;

final class MpegTsValidator
{
    public static function syncOffset(string $buffer): ?int
    {
        $limit = min(188, strlen($buffer));
        for ($offset = 0; $offset < $limit; ++$offset) {
            if (strlen($buffer) > $offset + 376
                && ord($buffer[$offset]) === 0x47
                && ord($buffer[$offset + 188]) === 0x47
                && ord($buffer[$offset + 376]) === 0x47) {
                return $offset;
            }
        }

        return null;
    }
}
