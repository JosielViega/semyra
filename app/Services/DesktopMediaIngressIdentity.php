<?php

declare(strict_types=1);

namespace App\Services;

final class DesktopMediaIngressIdentity
{
    public function __construct(private readonly string $namespace)
    {
        if (trim($namespace) === '') {
            throw new \InvalidArgumentException('LiveKit namespace must not be empty.');
        }
    }

    public function name(int $roomId): string
    {
        if ($roomId < 1) {
            throw new \InvalidArgumentException('Room ID must be positive.');
        }
        return 'smy_d_' . substr(hash('sha256', "semyra-desktop-ingress-v1\0{$this->namespace}\0{$roomId}"), 0, 32);
    }
}
