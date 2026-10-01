<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\RoomLiveKitController;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Repositories\RoomRepository;
use App\Repositories\RoomTransmissionRepository;
use App\Repositories\UserRepository;
use App\Services\AuthSession;
use App\Services\LiveKitRoomContext;
use App\Services\LiveKitViewerTokenService;
use App\Services\RoomParticipantSession;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoomLiveKitControllerTest extends TestCase
{
    private const API_KEY = 'DUMMY_TEST_API_KEY';
    private const API_SECRET = 'DUMMY_TEST_API_SECRET_NOT_REAL_0123456789ABCDEF';

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testInvalidCsrfReturns419(): void
    {
        [$controller] = $this->controller(validCsrf: false);
        $this->assertError($controller->viewerToken('ROOM1234'), 419, 'invalid_csrf');
    }

    public function testMissingRoomReturns404(): void
    {
        [$controller] = $this->controller(roomExists: false);
        $this->assertError($controller->viewerToken('ROOM1234'), 404, 'room_not_found');
    }

    public function testJoinedIdentityIsRequired(): void
    {
        [$controller] = $this->controller(joined: false);
        $this->assertError($controller->viewerToken('ROOM1234'), 403, 'join_required');
    }

    public function testAuthenticatedAccountMismatchIsRejected(): void
    {
        [$controller] = $this->controller(authUserId: 7, identityUserId: 8, users: [7 => 'Josiel', 8 => 'Other']);
        $this->assertError($controller->viewerToken('ROOM1234'), 403, 'join_required');
    }

    public function testMissingTransmissionReturns409(): void
    {
        [$controller] = $this->controller(transmission: null);
        $this->assertError($controller->viewerToken('ROOM1234'), 409, 'transmission_not_found');
    }

    public function testYoutubeTransmissionIsNotApplicable(): void
    {
        [$controller] = $this->controller(transmission: $this->transmission(sourceType: 'youtube'));
        $this->assertError($controller->viewerToken('ROOM1234'), 409, 'livekit_not_applicable');
    }

    public function testNonLiveIptvTransmissionIsNotApplicable(): void
    {
        [$controller] = $this->controller(transmission: $this->transmission(mediaMode: 'vod'));
        $this->assertError($controller->viewerToken('ROOM1234'), 409, 'livekit_not_applicable');
    }

    #[DataProvider('invalidRevisions')]
    public function testTransmissionRevisionMustBePositiveInteger(mixed $revision): void
    {
        [$controller] = $this->controller(revision: $revision);
        $this->assertError($controller->viewerToken('ROOM1234'), 422, 'invalid_transmission_revision');
    }

    public static function invalidRevisions(): array
    {
        return [[null], [''], ['0'], ['1.5'], ['01'], [[]]];
    }

    public function testStaleTransmissionRevisionReturnsConflict(): void
    {
        [$controller] = $this->controller(revision: '2');
        $this->assertError($controller->viewerToken('ROOM1234'), 409, 'transmission_changed');
    }

    public function testDisabledAndIncompleteLiveKitReturnSameSafeError(): void
    {
        [$disabled] = $this->controller(config: $this->config(enabled: false));
        [$incomplete] = $this->controller(config: $this->config(apiSecret: ''));

        $this->assertError($disabled->viewerToken('ROOM1234'), 503, 'livekit_unavailable');
        $this->assertError($incomplete->viewerToken('ROOM1234'), 503, 'livekit_unavailable');
    }

    public function testSuccessReturnsOnlyRevisionBoundViewerConnectionDetailsWithNoStoreHeaders(): void
    {
        [$controller] = $this->controller();
        $response = $controller->viewerToken('ROOM1234');
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->status());
        self::assertSame([
            'server_url',
            'participant_token',
            'transmission_revision',
            'publisher_identity',
        ], array_keys($body));
        self::assertSame('wss://unit-test.invalid', $body['server_url']);
        self::assertSame(3, $body['transmission_revision']);
        self::assertSame(
            (new LiveKitRoomContext('testing'))->publisherIdentity(7, 3, '2026-10-01 12:00:00.000'),
            $body['publisher_identity'],
        );
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertSame('no-cache', $response->headers()['Pragma']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
        self::assertSame('application/json; charset=UTF-8', $response->headers()['Content-Type']);

        $publicBody = $body;
        unset($publicBody['participant_token']);
        $publicJson = json_encode($publicBody, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString(self::API_KEY, $publicJson);
        self::assertStringNotContainsString(self::API_SECRET, $response->body());
        self::assertArrayNotHasKey('api_key', $body);
        self::assertArrayNotHasKey('api_secret', $body);
        self::assertArrayNotHasKey('room_name', $body);
        self::assertStringNotContainsString('ROOM1234', $publicJson);
        self::assertStringNotContainsString('Pedro', $publicJson);

        $claims = JWT::decode($body['participant_token'], new Key(self::API_SECRET, 'HS256'));
        self::assertSame('smy_r_b3a1dea6c353154d1c4dddfa87ead18c', $claims->video->room);
        self::assertMatchesRegularExpression('/^smy_v_[a-f0-9]{48}$/', $claims->sub);
    }

    public function testEveryIssueGetsNewViewerIdentityButKeepsPublisherForSameRevision(): void
    {
        [$firstController] = $this->controller();
        [$secondController] = $this->controller();
        $first = json_decode($firstController->viewerToken('ROOM1234')->body(), true, flags: JSON_THROW_ON_ERROR);
        $second = json_decode($secondController->viewerToken('ROOM1234')->body(), true, flags: JSON_THROW_ON_ERROR);
        $firstClaims = JWT::decode($first['participant_token'], new Key(self::API_SECRET, 'HS256'));
        $secondClaims = JWT::decode($second['participant_token'], new Key(self::API_SECRET, 'HS256'));

        self::assertNotSame($firstClaims->sub, $secondClaims->sub);
        self::assertSame($first['publisher_identity'], $second['publisher_identity']);
    }

    public function testPublisherIdentityChangesForNewTransmissionInstanceWithRestartedRevision(): void
    {
        [$firstController] = $this->controller(transmission: $this->transmission(
            revision: 1,
            startedAt: '2026-10-01 12:00:00.000',
        ), revision: '1');
        [$secondController] = $this->controller(transmission: $this->transmission(
            revision: 1,
            startedAt: '2026-10-01 12:05:00.000',
        ), revision: '1');
        $first = json_decode($firstController->viewerToken('ROOM1234')->body(), true, flags: JSON_THROW_ON_ERROR);
        $second = json_decode($secondController->viewerToken('ROOM1234')->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertNotSame($first['publisher_identity'], $second['publisher_identity']);
    }

    /** @return array{RoomLiveKitController, LiveKitControllerPdo} */
    private function controller(
        bool $validCsrf = true,
        bool $roomExists = true,
        bool $joined = true,
        mixed $revision = '3',
        array|false|null $transmission = false,
        ?array $config = null,
        ?int $authUserId = null,
        ?int $identityUserId = null,
        array $users = [],
    ): array {
        $pdo = new LiveKitControllerPdo();
        $pdo->roomExists = $roomExists;
        $pdo->transmission = $transmission === false ? $this->transmission() : $transmission;
        $pdo->users = $users;
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $pdo);

        $session = new Session(false);
        $csrf = new Csrf($session);
        $csrfToken = $csrf->token();
        $participantSession = new RoomParticipantSession($session);
        if ($joined) {
            if ($identityUserId === null) {
                $participantSession->rememberGuest('ROOM1234', 'Pedro');
            } else {
                $participantSession->rememberAccount('ROOM1234', $identityUserId, $users[$identityUserId] ?? 'Account');
            }
        }
        $auth = new AuthSession($session);
        if ($authUserId !== null) {
            $auth->login($authUserId);
        }

        return [new RoomLiveKitController(
            new Request(parsedBody: [
                '_token' => $validCsrf ? $csrfToken : 'invalid-token',
                'transmission_revision' => $revision,
            ]),
            $csrf,
            new RoomRepository($database),
            new RoomTransmissionRepository($database),
            $participantSession,
            new UserRepository($database),
            $auth,
            new LiveKitRoomContext('testing'),
            new LiveKitViewerTokenService($config ?? $this->config()),
        ), $pdo];
    }

    /** @return array<string, mixed> */
    private function transmission(
        string $sourceType = 'iptv',
        string $mediaMode = 'live',
        int $revision = 3,
        string $startedAt = '2026-10-01 12:00:00.000',
    ): array
    {
        return [
            'room_id' => 7,
            'owner_participant_key_hash' => str_repeat('a', 64),
            'owner_user_id' => null,
            'source_type' => $sourceType,
            'youtube_video_id' => null,
            'media_mode' => $mediaMode,
            'revision' => $revision,
            'playback_state' => 'playing',
            'playback_position_ms' => 0,
            'playback_at_live_edge' => 1,
            'playback_revision' => 1,
            'playback_age_ms' => 0,
            'projected_live_edge_position_ms' => null,
            'started_at' => $startedAt,
            'updated_at' => '2026-10-01 12:00:00.000',
            'owner_name' => 'Owner',
        ];
    }

    /** @return array<string, mixed> */
    private function config(bool $enabled = true, string $apiSecret = self::API_SECRET): array
    {
        return [
            'enabled' => $enabled,
            'url' => 'wss://unit-test.invalid',
            'api_key' => self::API_KEY,
            'api_secret' => $apiSecret,
            'namespace' => 'testing',
            'token_ttl_seconds' => 600,
        ];
    }

    private function assertError(\App\Core\Response $response, int $status, string $error): void
    {
        self::assertSame($status, $response->status());
        self::assertSame(['error' => $error], json_decode($response->body(), true));
        self::assertSame('no-store', $response->headers()['Cache-Control']);
    }
}

final class LiveKitControllerPdo extends PDO
{
    public bool $roomExists = true;
    public ?array $transmission = null;
    /** @var array<int, string> */
    public array $users = [];

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new LiveKitControllerStatement($this, $query);
    }
}

final class LiveKitControllerStatement extends PDOStatement
{
    private array $params = [];

    public function __construct(
        private readonly LiveKitControllerPdo $pdo,
        private readonly string $query,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];

        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->query, 'FROM rooms')) {
            return $this->pdo->roomExists ? [
                'id' => 7,
                'code' => 'ROOM1234',
                'created_by_user_id' => null,
                'last_activity_at' => '2026-10-01 12:00:00.000',
                'created_at' => '2026-10-01 12:00:00.000',
            ] : false;
        }
        if (str_contains($this->query, 'FROM room_transmissions transmission')) {
            return $this->pdo->transmission ?? false;
        }
        if (str_contains($this->query, 'FROM users')) {
            $id = (int) ($this->params['id'] ?? 0);
            if (!isset($this->pdo->users[$id])) {
                return false;
            }

            return [
                'id' => $id,
                'display_name' => $this->pdo->users[$id],
                'email' => 'user' . $id . '@example.test',
                'created_at' => '2026-10-01 12:00:00.000',
                'updated_at' => '2026-10-01 12:00:00.000',
            ];
        }

        return false;
    }
}
