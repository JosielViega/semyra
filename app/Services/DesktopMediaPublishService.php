<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RoomTransmissionRepository;
use Throwable;

final class DesktopMediaPublishService
{
    public function __construct(
        private readonly DesktopHostSessionService $sessions,
        private readonly RoomTransmissionRepository $transmissions,
        private readonly LiveKitIngressGateway $ingress,
        private readonly LiveKitRoomContext $context,
        private readonly DesktopMediaIngressIdentity $identity,
        private readonly bool $liveKitAvailable,
    ) {
    }

    /** @return array{ingress_id:string,whip_endpoint:string,transmission_instance_id:string,transmission_revision:int} */
    public function start(string $token, int $roomId, string $instanceId, int $revision): array
    {
        $this->authorize($token, $roomId, $instanceId, $revision);
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

    private function authorize(string $token, int $roomId, string $instanceId, int $revision): void
    {
        $validation = $this->sessions->validateResult($token, $roomId, $instanceId, $revision, DesktopHostSessionService::PERMISSION);
        if ($validation['session'] === null) {
            throw new DesktopMediaPublishException($validation['error'] ?? 'invalid_host_session', 401);
        }
    }
}
