<?php

declare(strict_types=1);

namespace App\Services;

final class RoomCodeGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const LENGTH = 8;

    public function generate(): string
    {
        $code = '';
        $lastIndex = strlen(self::ALPHABET) - 1;

        for ($position = 0; $position < self::LENGTH; ++$position) {
            $code .= self::ALPHABET[random_int(0, $lastIndex)];
        }

        return $code;
    }
}
