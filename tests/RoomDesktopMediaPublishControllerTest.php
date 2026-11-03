<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\RoomDesktopMediaPublishController;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Repositories\DesktopHostSessionRepository;
use App\Repositories\RoomRepository;
use App\Repositories\RoomTransmissionRepository;
use App\Repositories\UserRepository;
use App\Services\DesktopHostSessionService;
use App\Services\DesktopMediaIngressIdentity;
use App\Services\DesktopMediaPublishService;
use App\Services\LiveKitIngressGateway;
use App\Services\LiveKitRoomContext;
use App\Services\PremiumAccess;
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

    public function testStartWritesOnlySanitizedReachAndOutcomeDiagnostics(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'semyra-http-log-' . bin2hex(random_bytes(6));
        $logger = new Logger($directory);
        [$controller] = $this->controller(false, false, $logger);

        self::assertSame(201, $controller->start('ROOM2345')->status());

        $path = $directory . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';
        $contents = (string) file_get_contents($path);
        self::assertStringContainsString('request reached PHP', $contents);
        self::assertStringContainsString('request completed in PHP', $contents);
        self::assertStringNotContainsString('Bearer', $contents);
        self::assertStringNotContainsString(str_repeat('a', 32), $contents);
        self::assertStringNotContainsString(str_repeat('b', 64), $contents);
        unlink($path);
        rmdir($directory);
    }

    private function controller(bool $omitBearer, bool $extraChannel = false, ?Logger $logger = null): array
    {
        $instance = str_repeat('a', 32);
        $owner = str_repeat('b', 64);
        $pdo = new DesktopHostSessionPdo();
        $pdo->seedUser(7);
        $pdo->transmission = ['room_id' => 7, 'instance_id' => $instance, 'revision' => 3,
            'owner_user_id' => 7, 'owner_participant_key_hash' => $owner,
            'source_type' => 'iptv', 'media_mode' => 'live'];
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $pdo);
        $sessions = new DesktopHostSessionService(new DesktopHostSessionRepository($database), 600);
        $issued = $sessions->issue(7, $instance, 3, 7, $owner);
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
            $context, new DesktopMediaIngressIdentity('testing'), new UserRepository($database), new PremiumAccess(), true, $logger);
        return [new RoomDesktopMediaPublishController($request, new RoomRepository($database), $service, $logger), $gateway];
    }
}

final class ControllerDesktopPublishGateway implements LiveKitIngressGateway
{
    public function findIngressStatus(string $roomName, string $ingressName, string $publisherIdentity, string $ingressId): ?string { return 'publishing'; }
    public int $creates = 0;
    public function createWhipIngress(string $name, string $roomName, string $publisherIdentity, bool $screenShare = false): array
    {
        ++$this->creates;
        return ['ingress_id' => 'INGRESS_CONTROLLER', 'whip_endpoint' => 'https://whip.invalid/controller'];
    }
    public function findOwnedIngressIds(string $roomName, string $ingressName, string $publisherIdentity): array { return []; }
    public function findIngressIdsByRoomAndName(string $roomName, string $ingressName): array { return []; }
    public function deleteIngress(string $ingressId): void {}
}
