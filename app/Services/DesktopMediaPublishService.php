<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RoomTransmissionRepository;
use App\Repositories\UserRepository;
use Throwable;

final class DesktopMediaPublishService
{
    public function __construct(
        private readonly DesktopHostSessionService $sessions,
        private readonly RoomTransmissionRepository $transmissions,
        private readonly LiveKitIngressGateway $ingress,
        private readonly LiveKitRoomContext $context,
        private readonly DesktopMediaIngressIdentity $identity,
        private readonly UserRepository $users,
        private readonly PremiumAccess $premiumAccess,
        private readonly bool $liveKitAvailable,
    ) {
    }

    /** @return array{ingress_id:string,whip_endpoint:string,transmission_instance_id:string,transmission_revision:int} */
    public function start(string $token, int $roomId, string $instanceId, int $revision): array
    {
        $session = $this->authorize($token, $roomId, $instanceId, $revision);
        $ownerUserId = $session['owner_user_id'];
        $owner = $ownerUserId === null ? null : $this->users->findById($ownerUserId);
        if ($owner === null || !$this->premiumAccess->canPublishIptv($owner)) {
            throw new DesktopMediaPublishException('premium_required', 403);
        }
        $current = $this->transmissions->findByRoom($roomId);
        if ($current === null
            || !hash_equals((string) ($current['instance_id'] ?? ''), $instanceId)
            || (int) ($current['revision'] ?? 0) !== $revision) {
            throw new DesktopMediaPublishException('transmission_changed', 409);
        }
        if (($current['source_type'] ?? null) !== 'iptv' || ($current['media_mode'] ?? null) !== 'live') {
            throw new DesktopMediaPublishException('publish_not_applicable', 409);
        }
        if (!$this->liveKitAvailable) {
            throw new DesktopMediaPublishException('livekit_unavailable', 503);
        }

        $roomName = $this->context->roomName($roomId);
        $ingressName = $this->identity->name($roomId);
        try {
            foreach ($this->ingress->findIngressIdsByRoomAndName($roomName, $ingressName) as $staleId) {
                $this->ingress->deleteIngress($staleId);
            }
            $created = $this->ingress->createWhipIngress(
                $ingressName,
                $roomName,
                $this->context->publisherIdentity($roomId, $instanceId),
            );
        } catch (Throwable) {
            throw new DesktopMediaPublishException('ingress_create_failed', 503);
        }

        return $created + [
            'transmission_instance_id' => $instanceId,
            'transmission_revision' => $revision,
        ];
    }

    public function stop(string $token, int $roomId, string $instanceId, int $revision, string $ingressId): void
    {
        $this->authorize($token, $roomId, $instanceId, $revision);
        try {
            $owned = $this->ingress->findIngressIdsByRoomAndName(
                $this->context->roomName($roomId),
                $this->identity->name($roomId),
            );
            if (in_array($ingressId, $owned, true)) {
                $this->ingress->deleteIngress($ingressId);
            }
        } catch (Throwable) {
            throw new DesktopMediaPublishException('ingress_cleanup_failed', 503);
        }
    }

    /** @return array{room_id: int, transmission_instance_id: string, transmission_revision: int, owner_user_id: null|int, owner_participant_key_hash: string, permission: string, expires_at: string} */
    private function authorize(string $token, int $roomId, string $instanceId, int $revision): array
    {
        $validation = $this->sessions->validateResult($token, $roomId, $instanceId, $revision, DesktopHostSessionService::PERMISSION);
        if ($validation['session'] === null) {
            throw new DesktopMediaPublishException($validation['error'] ?? 'invalid_host_session', 401);
        }

        return $validation['session'];
    }
}
