<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\RoomDesktopMediaPublishController;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\DesktopHostSessionRepository;
use App\Repositories\RoomRepository;
use App\Repositories\RoomTransmissionRepository;
use App\Services\DesktopHostSessionService;
use App\Services\DesktopMediaIngressIdentity;
use App\Services\DesktopMediaPublishService;
use App\Services\LiveKitIngressGateway;
use App\Services\LiveKitRoomContext;
use PHPUnit\Framework\TestCase;
use Tests\Support\DesktopHostSessionPdo;

final class RoomDesktopMediaPublishControllerTest extends TestCase
{
    public function testValidBearerCreatesNoStoreResponseWithoutChannelId(): void
    {
        [$controller, $gateway] = $this->controller(false);
        $response = $controller->start('ROOM2345');
        $body = json_decode($response->body(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->status());
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertSame('INGRESS_CONTROLLER', $body['ingress_id']);
        self::assertSame(1, $gateway->creates);
        self::assertArrayNotHasKey('channel_id', $body);
    }

    public function testMissingBearerAndExtraChannelIdAreRejected(): void
    {
        [$missing] = $this->controller(true);
        self::assertSame(401, $missing->start('ROOM2345')->status());

        [$extra] = $this->controller(false, true);
        self::assertSame(422, $extra->start('ROOM2345')->status());
    }

    private function controller(bool $omitBearer, bool $extraChannel = false): array
    {
        $instance = str_repeat('a', 32);
        $owner = str_repeat('b', 64);
        $pdo = new DesktopHostSessionPdo();
        $pdo->transmission = ['room_id' => 7, 'instance_id' => $instance, 'revision' => 3,
            'owner_user_id' => null, 'owner_participant_key_hash' => $owner,
            'source_type' => 'iptv', 'media_mode' => 'live'];
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $pdo);
        $sessions = new DesktopHostSessionService(new DesktopHostSessionRepository($database), 600);
        $issued = $sessions->issue(7, $instance, 3, null, $owner);
        self::assertNotNull($issued);
        $payload = ['transmission_instance_id' => $instance, 'transmission_revision' => 3];
        if ($extraChannel) {
            $payload['channelId'] = 99;
        }
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (!$omitBearer) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $issued['token'];
        }
        $request = new Request([], [], $server, [], [], json_encode($payload, JSON_THROW_ON_ERROR));
        $gateway = new ControllerDesktopPublishGateway();
        $context = new LiveKitRoomContext('testing');
        $service = new DesktopMediaPublishService($sessions, new RoomTransmissionRepository($database), $gateway,
            $context, new DesktopMediaIngressIdentity('testing'), true);
        return [new RoomDesktopMediaPublishController($request, new RoomRepository($database), $service), $gateway];
    }
}

final class ControllerDesktopPublishGateway implements LiveKitIngressGateway
{
    public int $creates = 0;
    public function createWhipIngress(string $name, string $roomName, string $publisherIdentity): array
    {
        ++$this->creates;
        return ['ingress_id' => 'INGRESS_CONTROLLER', 'whip_endpoint' => 'https://whip.invalid/controller'];
    }
    public function findOwnedIngressIds(string $roomName, string $ingressName, string $publisherIdentity): array { return []; }
    public function findIngressIdsByRoomAndName(string $roomName, string $ingressName): array { return []; }
    public function deleteIngress(string $ingressId): void {}
}
