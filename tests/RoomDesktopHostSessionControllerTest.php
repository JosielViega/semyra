<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\RoomDesktopHostSessionController;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Repositories\DesktopHostSessionRepository;
use App\Repositories\RoomRepository;
use App\Repositories\RoomTransmissionRepository;
use App\Repositories\UserRepository;
use App\Services\AuthSession;
use App\Services\DesktopHostSessionService;
use App\Services\RoomParticipantSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\DesktopHostSessionPdo;

final class RoomDesktopHostSessionControllerTest extends TestCase
{
    private const INSTANCE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testGuestOwnerReceivesBoundTokenWithNoStoreHeaders(): void
    {
        [$controller, $pdo] = $this->controller();
        $response = $controller->issue('ROOM2345');
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->status());
        self::assertSame('media.publish', $body['permission']);
        self::assertSame(self::INSTANCE, $body['transmission_instance_id']);
        self::assertSame(3, $body['transmission_revision']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.[a-f0-9]{64}$/', $body['host_session_token']);
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertSame('no-cache', $response->headers()['Pragma']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
        [$selector, $validator] = explode('.', $body['host_session_token'], 2);
        self::assertSame(hash('sha256', $validator), $pdo->sessions[$selector]['validator_hash']);
        self::assertArrayNotHasKey('owner_user_id', $body);
        self::assertArrayNotHasKey('owner_participant_key_hash', $body);
    }

    public function testAuthenticatedOwnerIsAccepted(): void
    {
        [$controller, $pdo] = $this->controller(authenticated: true);
        $response = $controller->issue('ROOM2345');

        self::assertSame(201, $response->status());
        self::assertSame(7, array_values($pdo->sessions)[0]['owner_user_id']);
    }

    public function testNonOwnerIsRejected(): void
    {
        [$controller] = $this->controller(owner: false);
        $this->assertError($controller->issue('ROOM2345'), 403, 'owner_required');
    }

    #[DataProvider('inapplicableTransmissions')]
    public function testOnlyIptvLiveIsApplicable(string $source, string $mediaMode): void
    {
        [$controller] = $this->controller(source: $source, mediaMode: $mediaMode);
        $this->assertError($controller->issue('ROOM2345'), 409, 'host_session_not_applicable');
    }

    public static function inapplicableTransmissions(): array
    {
        return [['youtube', 'live'], ['iptv', 'vod']];
    }

    public function testStaleRequestAndAtomicTransmissionChangeAreRejected(): void
    {
        [$stale] = $this->controller(requestedRevision: '4');
        $this->assertError($stale->issue('ROOM2345'), 409, 'transmission_changed');

        [$racing, $pdo] = $this->controller();
        $pdo->changeBeforeHostInsert = true;
        $this->assertError($racing->issue('ROOM2345'), 409, 'transmission_changed');
        self::assertSame([], $pdo->sessions);
    }

    public function testValidationErrorsAreStable(): void
    {
        [$csrf] = $this->controller(validCsrf: false);
        $this->assertError($csrf->issue('ROOM2345'), 419, 'invalid_csrf');
        [$instance] = $this->controller(requestedInstance: 'invalid');
        $this->assertError($instance->issue('ROOM2345'), 422, 'invalid_transmission_instance_id');
        [$revision] = $this->controller(requestedRevision: '0');
        $this->assertError($revision->issue('ROOM2345'), 422, 'invalid_transmission_revision');
    }

    /** @return array{RoomDesktopHostSessionController, DesktopHostSessionPdo} */
    private function controller(
        bool $validCsrf = true,
        bool $authenticated = false,
        bool $owner = true,
        string $source = 'iptv',
        string $mediaMode = 'live',
        mixed $requestedInstance = self::INSTANCE,
        mixed $requestedRevision = '3',
    ): array {
        $pdo = new DesktopHostSessionPdo();
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $pdo);
        $session = new Session(false);
        $csrf = new Csrf($session);
        $participantSession = new RoomParticipantSession($session);
        $auth = new AuthSession($session);

        if ($authenticated) {
            $pdo->seedUser(7);
            $auth->login(7);
            $identity = $participantSession->rememberAccount('ROOM2345', 7, 'Account Owner');
        } else {
            $identity = $participantSession->rememberGuest('ROOM2345', 'Guest Owner');
        }
        $participantHash = hash('sha256', $identity['participant_key']);
        $pdo->transmission = [
            'room_id' => 7,
            'instance_id' => self::INSTANCE,
            'owner_participant_key_hash' => $owner ? $participantHash : str_repeat('f', 64),
            'owner_user_id' => $authenticated ? ($owner ? 7 : 8) : null,
            'source_type' => $source,
            'youtube_video_id' => $source === 'youtube' ? 'abcdefghijk' : null,
            'media_mode' => $mediaMode,
            'revision' => 3,
            'playback_state' => 'playing',
            'playback_position_ms' => 0,
            'playback_at_live_edge' => 1,
            'playback_revision' => 1,
            'playback_age_ms' => 0,
            'projected_live_edge_position_ms' => null,
            'started_at' => '2026-10-06 12:00:00.000',
            'updated_at' => '2026-10-06 12:00:00.000',
            'owner_name' => 'Owner',
        ];
        $repository = new DesktopHostSessionRepository($database);
        $controller = new RoomDesktopHostSessionController(
            new Request(parsedBody: [
                '_token' => $validCsrf ? $csrf->token() : 'invalid',
                'transmission_instance_id' => $requestedInstance,
                'transmission_revision' => $requestedRevision,
            ]),
            $csrf,
            new RoomRepository($database),
            new RoomTransmissionRepository($database),
            $participantSession,
            new UserRepository($database),
            $auth,
            new DesktopHostSessionService($repository, 600),
        );

        return [$controller, $pdo];
    }

    private function assertError(\App\Core\Response $response, int $status, string $error): void
    {
        self::assertSame($status, $response->status());
        self::assertSame(['error' => $error], json_decode($response->body(), true));
        self::assertSame('no-store', $response->headers()['Cache-Control']);
    }
}
