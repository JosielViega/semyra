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
        $current = $this->transmissions->findByRoom($roomId);
        if ($current === null
            || !hash_equals((string) ($current['instance_id'] ?? ''), $instanceId)
            || (int) ($current['revision'] ?? 0) !== $revision) {
            throw new DesktopMediaPublishException('transmission_changed', 409);
        }
        $sourceType = $current['source_type'] ?? null;
        $permission = $sourceType === 'screen'
            ? DesktopHostSessionService::SCREEN_PERMISSION
            : DesktopHostSessionService::PERMISSION;
        $session = $this->authorize($token, $roomId, $instanceId, $revision, $permission);
        $ownerUserId = $session['owner_user_id'];
        $owner = $ownerUserId === null ? null : $this->users->findById($ownerUserId);
        $entitled = $owner !== null && ($sourceType === 'screen'
            ? $this->premiumAccess->canShareScreen($owner)
            : $this->premiumAccess->canPublishIptv($owner));
        if (!$entitled) {
            throw new DesktopMediaPublishException('premium_required', 403);
        }
        if (!(($sourceType === 'iptv' && in_array(($current['media_mode'] ?? null), ['live', 'vod'], true))
            || ($sourceType === 'screen' && ($current['media_mode'] ?? null) === 'live'))) {
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
                $sourceType === 'screen',
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
        $this->authorizeCleanup($token, $roomId, $instanceId, $revision);
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

    /** @return array{state:string} */
    public function status(string $token, int $roomId, string $instanceId, int $revision, string $ingressId): array
    {
        $current = $this->transmissions->findByRoom($roomId);
        if ($current === null
            || !hash_equals((string) ($current['instance_id'] ?? ''), $instanceId)
            || (int) ($current['revision'] ?? 0) !== $revision) {
            throw new DesktopMediaPublishException('transmission_changed', 409);
        }
        $sourceType = $current['source_type'] ?? null;
        $permission = $sourceType === 'screen'
            ? DesktopHostSessionService::SCREEN_PERMISSION
            : DesktopHostSessionService::PERMISSION;
        $this->authorize($token, $roomId, $instanceId, $revision, $permission);
        try {
            $state = $this->ingress->findIngressStatus(
                $this->context->roomName($roomId),
                $this->identity->name($roomId),
                $this->context->publisherIdentity($roomId, $instanceId),
                $ingressId,
            );
        } catch (Throwable) {
            throw new DesktopMediaPublishException('ingress_status_failed', 503);
        }
        if ($state === null) {
            throw new DesktopMediaPublishException('ingress_not_found', 404);
        }
        return ['state' => $state];
    }

    private function authorizeCleanup(string $token, int $roomId, string $instanceId, int $revision): void
    {
        $screen = $this->sessions->validateResult(
            $token,
            $roomId,
            $instanceId,
            $revision,
            DesktopHostSessionService::SCREEN_PERMISSION,
        );
        if ($screen['session'] !== null) {
            return;
        }
        $media = $this->sessions->validateResult(
            $token,
            $roomId,
            $instanceId,
            $revision,
            DesktopHostSessionService::PERMISSION,
        );
        if ($media['session'] === null) {
            throw new DesktopMediaPublishException(
                $screen['error'] === 'host_session_expired' || $media['error'] === 'host_session_expired'
                    ? 'host_session_expired'
                    : 'invalid_host_session',
                401,
            );
        }
    }

    /** @return array{room_id: int, transmission_instance_id: string, transmission_revision: int, owner_user_id: null|int, owner_participant_key_hash: string, permission: string, expires_at: string} */
    private function authorize(string $token, int $roomId, string $instanceId, int $revision, string $permission): array
    {
        $validation = $this->sessions->validateResult($token, $roomId, $instanceId, $revision, $permission);
        if ($validation['session'] === null) {
            throw new DesktopMediaPublishException($validation['error'] ?? 'invalid_host_session', 401);
        }

        return $validation['session'];
    }
}
