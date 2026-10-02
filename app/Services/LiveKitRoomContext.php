<?php

declare(strict_types=1);

namespace App\Services;

final class LiveKitRoomContext
{
    public function __construct(private readonly string $namespace)
    {
        if (trim($namespace) === '') {
            throw new \InvalidArgumentException('LiveKit namespace must not be empty.');
        }
    }

    public function roomName(int $roomId): string
    {
        $this->assertPositive($roomId, 'Room ID');

        return 'smy_r_' . $this->digest('room:' . $roomId);
    }

    public function publisherIdentity(int $roomId, string $instanceId): string
    {
        $this->assertPositive($roomId, 'Room ID');
        if (preg_match('/^[a-f0-9]{32}$/', $instanceId) !== 1) {
            throw new \InvalidArgumentException('Transmission instance ID is invalid.');
        }

        return 'smy_i_' . $this->digest(
            'publisher:' . $roomId . ':' . $instanceId,
        );
    }

    public function viewerIdentity(): string
    {
        return 'smy_v_' . bin2hex(random_bytes(24));
    }

    private function digest(string $value): string
    {
        return substr(hash('sha256', "semyra-livekit-v1\0" . $this->namespace . "\0" . $value), 0, 32);
    }

    private function assertPositive(int $value, string $label): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException($label . ' must be positive.');
        }
    }

}
