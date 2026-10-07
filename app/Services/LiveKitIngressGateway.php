<?php

declare(strict_types=1);

namespace App\Services;

interface LiveKitIngressGateway
{
    /** @return array{ingress_id: string, whip_endpoint: string} */
    public function createWhipIngress(string $name, string $roomName, string $publisherIdentity): array;

    /** @return list<string> */
    public function findOwnedIngressIds(string $roomName, string $ingressName, string $publisherIdentity): array;

    /** @return list<string> */
    public function findIngressIdsByRoomAndName(string $roomName, string $ingressName): array;

    public function deleteIngress(string $ingressId): void;
}
