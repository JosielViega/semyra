<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Database;
use App\Repositories\DesktopHostSessionRepository;
use App\Repositories\RoomTransmissionRepository;
use App\Services\DesktopHostSessionService;
use App\Services\DesktopMediaIngressIdentity;
use App\Services\DesktopMediaPublishException;
use App\Services\DesktopMediaPublishService;
use App\Services\LiveKitIngressGateway;
use App\Services\LiveKitRoomContext;
use PHPUnit\Framework\TestCase;
use Tests\Support\DesktopHostSessionPdo;

final class DesktopMediaPublishServiceTest extends TestCase
{
    private const INSTANCE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OWNER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testStartCleansOnlyExactDesktopIngressAndCreatesWhip(): void
    {
        [$service, $token, $gateway, $identity, $context] = $this->fixture();
        $desktopName = $identity->name(7);
        $roomName = $context->roomName(7);
        $gateway->items = [
            ['id' => 'OLD_DESKTOP', 'room' => $roomName, 'name' => $desktopName],
            ['id' => 'BRIDGE', 'room' => $roomName, 'name' => 'smy_b_' . self::INSTANCE . '_a1'],
        ];

        $result = $service->start($token, 7, self::INSTANCE, 3);

        self::assertSame(['OLD_DESKTOP'], $gateway->deleted);
        self::assertSame($desktopName, $gateway->created[0]['name']);
        self::assertSame('INGRESS_NEW', $result['ingress_id']);
        self::assertNotContains('BRIDGE', $gateway->deleted);
    }

    public function testChangedTransmissionAndInvalidTokenAreRejectedBeforeProvisioning(): void
    {
        [$service, $token, $gateway] = $this->fixture();
        try {
            $service->start($token, 7, str_repeat('c', 32), 3);
            self::fail('Expected authorization failure.');
        } catch (DesktopMediaPublishException $exception) {
            self::assertSame('invalid_host_session', $exception->errorCode);
        }
        self::assertSame([], $gateway->created);
    }

    public function testExpiredTokenAndNonApplicableTransmissionAreRejected(): void
    {
        [$service, $token, $gateway, , , $pdo] = $this->fixture();
        [$selector] = explode('.', $token, 2);
        $pdo->sessions[$selector]['expired'] = true;
        try {
            $service->start($token, 7, self::INSTANCE, 3);
            self::fail('Expected expired Host Session.');
        } catch (DesktopMediaPublishException $exception) {
            self::assertSame('host_session_expired', $exception->errorCode);
        }
        $pdo->sessions[$selector]['expired'] = false;
        $pdo->transmission['source_type'] = 'youtube';
        try {
            $service->start($token, 7, self::INSTANCE, 3);
            self::fail('Expected non-applicable transmission.');
        } catch (DesktopMediaPublishException $exception) {
            self::assertSame('publish_not_applicable', $exception->errorCode);
        }
        self::assertSame([], $gateway->created);
    }

    public function testStopIsIdempotentAndNeverDeletesAnUnownedIngressId(): void
    {
        [$service, $token, $gateway, $identity, $context] = $this->fixture();
        $gateway->items = [['id' => 'OWNED', 'room' => $context->roomName(7), 'name' => $identity->name(7)]];

        $service->stop($token, 7, self::INSTANCE, 3, 'FOREIGN');
        $service->stop($token, 7, self::INSTANCE, 3, 'OWNED');
        $service->stop($token, 7, self::INSTANCE, 3, 'OWNED');

        self::assertSame(['OWNED'], $gateway->deleted);
        self::assertNotContains('FOREIGN', $gateway->deleted);
    }

    public function testUnavailableLiveKitFailsBeforeIngressCreation(): void
    {
        [$service, $token, $gateway, $identity, $context, $pdo, $sessions, $repository] = $this->fixture();
        $unavailable = new DesktopMediaPublishService($sessions, $repository, $gateway, $context, $identity, false);
        try {
            $unavailable->start($token, 7, self::INSTANCE, 3);
            self::fail('Expected unavailable LiveKit.');
        } catch (DesktopMediaPublishException $exception) {
            self::assertSame('livekit_unavailable', $exception->errorCode);
        }
        self::assertSame([], $gateway->created);
    }

    private function fixture(): array
    {
        $pdo = new DesktopHostSessionPdo();
        $pdo->transmission = ['room_id' => 7, 'instance_id' => self::INSTANCE, 'revision' => 3,
            'owner_user_id' => null, 'owner_participant_key_hash' => self::OWNER,
            'source_type' => 'iptv', 'media_mode' => 'live'];
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $pdo);
        $sessions = new DesktopHostSessionService(new DesktopHostSessionRepository($database), 600);
        $issued = $sessions->issue(7, self::INSTANCE, 3, null, self::OWNER);
        self::assertNotNull($issued);
        $gateway = new DesktopPublishGateway();
        $context = new LiveKitRoomContext('testing');
        $identity = new DesktopMediaIngressIdentity('testing');
        $repository = new RoomTransmissionRepository($database);
        return [new DesktopMediaPublishService($sessions, $repository, $gateway, $context, $identity, true),
            $issued['token'], $gateway, $identity, $context, $pdo, $sessions, $repository];
    }
}

final class DesktopPublishGateway implements LiveKitIngressGateway
{
    public array $items = [];
    public array $deleted = [];
    public array $created = [];
    public function createWhipIngress(string $name, string $roomName, string $publisherIdentity): array
    {
        $this->created[] = compact('name', 'roomName', 'publisherIdentity');
        return ['ingress_id' => 'INGRESS_NEW', 'whip_endpoint' => 'https://whip.invalid/test'];
    }
    public function findOwnedIngressIds(string $roomName, string $ingressName, string $publisherIdentity): array { return []; }
    public function findIngressIdsByRoomAndName(string $roomName, string $ingressName): array
    {
        return array_values(array_map(static fn(array $item): string => $item['id'], array_filter(
            $this->items,
            static fn(array $item): bool => $item['room'] === $roomName && $item['name'] === $ingressName,
        )));
    }
    public function deleteIngress(string $ingressId): void
    {
        $this->deleted[] = $ingressId;
        $this->items = array_values(array_filter($this->items, static fn(array $item): bool => $item['id'] !== $ingressId));
    }
}
