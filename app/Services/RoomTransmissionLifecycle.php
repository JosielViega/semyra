<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RoomTransmissionRepository;

final class RoomTransmissionLifecycle
{
    public const OWNER_ACTIVITY_WINDOW_SECONDS = 5;

    public function __construct(private readonly RoomTransmissionRepository $transmissions)
    {
    }

    /** @return null|array<string, mixed> */
    public function reconcile(int $roomId): ?array
    {
        $observed = $this->transmissions->findByRoom($roomId);
        if ($observed === null) {
            return null;
        }

        $removed = $this->transmissions->endIfOwnerInactive(
            $roomId,
            (string) $observed['owner_participant_key_hash'],
            $observed['owner_user_id'] === null ? null : (int) $observed['owner_user_id'],
            (string) $observed['instance_id'],
            (int) $observed['revision'],
            self::OWNER_ACTIVITY_WINDOW_SECONDS,
        );

        return $removed ? null : $this->transmissions->findByRoom($roomId);
    }
}
