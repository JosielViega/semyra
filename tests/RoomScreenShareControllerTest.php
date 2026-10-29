<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\RoomScreenShareController;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Repositories\RoomRepository;
use App\Repositories\RoomTransmissionRepository;
use App\Repositories\UserRepository;
use App\Repositories\DesktopHostSessionRepository;
use App\Services\AuthSession;
use App\Services\DesktopHostSessionService;
use App\Services\PremiumAccess;
use App\Services\RoomParticipantSession;
use App\Services\RoomTransmissionPresenter;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class RoomScreenShareControllerTest extends TestCase
{
    protected function setUp(): void { $_SESSION = []; }

    public function testPremiumAccountStartsScreenAndReceivesRestrictedConnectionPayload(): void
    {
        [$controller, $pdo] = $this->controller(premium: true);
        $response = $controller->start('ROOM1234');
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->status());
        self::assertSame('screen', $body['transmission']['source']);
        self::assertSame('live', $body['transmission']['media_mode']);
        self::assertTrue($body['transmission']['is_owner']);
        self::assertSame('screen.publish', $body['permission']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.[a-f0-9]{64}$/', $body['host_session_token']);
        self::assertArrayNotHasKey('server_url', $body);
        self::assertArrayNotHasKey('participant_token', $body);
        self::assertArrayNotHasKey('api_secret', $body);
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertNotNull($pdo->transmission);
    }

    public function testFreeAccountAndGuestCannotStart(): void
    {
        [$free] = $this->controller(premium: false);
        self::assertSame('premium_required', $this->error($free->start('ROOM1234')));

        [$guest] = $this->controller(premium: false, authenticated: false);
        self::assertSame('authentication_required', $this->error($guest->start('ROOM1234')));
    }

    public function testHeartbeatAndStopAreFencedAndRemainAvailableAfterDowngrade(): void
    {
        [$start, $pdo, $identity] = $this->controller(premium: true);
        self::assertSame(201, $start->start('ROOM1234')->status());
        $instance = $pdo->transmission['instance_id'];
        $revision = (int) $pdo->transmission['revision'];

        [$heartbeat] = $this->controller(
            premium: false, pdo: $pdo, identity: $identity,
            instanceId: $instance, revision: (string) $revision,
        );
        self::assertSame(200, $heartbeat->heartbeat('ROOM1234')->status());

        [$stale] = $this->controller(
            premium: false, pdo: $pdo, identity: $identity,
            instanceId: str_repeat('b', 32), revision: (string) $revision,
        );
        self::assertSame('transmission_changed', $this->error($stale->stop('ROOM1234')));
        self::assertNotNull($pdo->transmission);

        [$stop] = $this->controller(
            premium: false, pdo: $pdo, identity: $identity,
            instanceId: $instance, revision: (string) $revision,
        );
        self::assertSame(200, $stop->stop('ROOM1234')->status());
        self::assertNull($pdo->transmission);
    }

    public function testLateStartFromSameAccountCannotIssueForOrDeleteNewerInstance(): void
    {
        $pdo = new ScreenShareControllerPdo();
        $pdo->replaceBeforeFind = true;
        [$controller] = $this->controller(premium: true, pdo: $pdo);

        self::assertSame('transmission_changed', $this->error($controller->start('ROOM1234')));
        self::assertSame(str_repeat('b', 32), $pdo->transmission['instance_id']);
        self::assertSame(7, $pdo->transmission['owner_user_id']);
    }

    /** @return array{RoomScreenShareController, ScreenShareControllerPdo, array<string, mixed>} */
    private function controller(
        bool $premium,
        bool $authenticated = true,
        ?ScreenShareControllerPdo $pdo = null,
        ?array $identity = null,
        string $instanceId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        string $revision = '1',
    ): array {
        $pdo ??= new ScreenShareControllerPdo();
        $pdo->premium = $premium;
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $pdo);
        $session = new Session(false);
        $csrf = new Csrf($session);
        $participantSession = new RoomParticipantSession($session);
        $identity ??= $participantSession->rememberAccount('ROOM1234', 7, 'Josiel');
        if ($participantSession->identityFor('ROOM1234') === null) {
            $_SESSION['room_participants']['ROOM1234'] = $identity;
        }
        $auth = new AuthSession($session);
        if ($authenticated) $auth->login(7);
        else $auth->logout();
        $request = new Request(parsedBody: [
            '_token' => $csrf->token(),
            'transmission_instance_id' => $instanceId,
            'transmission_revision' => $revision,
        ]);
        return [new RoomScreenShareController(
            $request, $csrf, new RoomRepository($database), new RoomTransmissionRepository($database),
            $participantSession, new UserRepository($database), $auth, new PremiumAccess(),
            new RoomTransmissionPresenter(),
            new DesktopHostSessionService(new DesktopHostSessionRepository($database), 600),
        ), $pdo, $identity];
    }

    private function error(\App\Core\Response $response): string
    {
        return (string) json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR)['error'];
    }
}

final class ScreenShareControllerPdo extends PDO
{
    public bool $premium = true;
    public bool $replaceBeforeFind = false;
    public ?array $transmission = null;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ScreenShareControllerStatement($this, $query);
    }
}

final class ScreenShareControllerStatement extends PDOStatement
{
    private array $params = [];
    private int $affected = 0;
    public function __construct(private readonly ScreenShareControllerPdo $pdo, private readonly string $query) {}
    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (str_starts_with($this->query, 'INSERT INTO room_transmissions')) {
            $this->pdo->transmission = [
                'room_id' => 7, 'instance_id' => $this->params['instance_id'],
                'owner_participant_key_hash' => $this->params['owner_participant_key_hash'],
                'owner_user_id' => $this->params['owner_user_id'], 'source_type' => 'screen',
                'youtube_video_id' => null, 'media_mode' => 'live', 'publisher_last_seen_at' => 'now',
                'revision' => ($this->pdo->transmission['revision'] ?? 0) + 1,
                'playback_state' => 'playing', 'playback_position_ms' => 0,
                'playback_at_live_edge' => 1, 'playback_revision' => 1, 'playback_age_ms' => 0,
                'projected_live_edge_position_ms' => null, 'started_at' => 'now', 'updated_at' => 'now',
                'owner_name' => 'Josiel',
            ];
            $this->affected = 1;
        } elseif (str_starts_with($this->query, 'INSERT INTO desktop_host_sessions')) {
            $this->affected = $this->matchesFence()
                && ($this->params['permission'] ?? null) === DesktopHostSessionService::SCREEN_PERMISSION ? 1 : 0;
        } elseif (str_starts_with($this->query, 'UPDATE room_transmissions SET publisher_last_seen_at')) {
            $this->affected = $this->matchesFence() ? 1 : 0;
        } elseif (str_starts_with($this->query, 'DELETE FROM room_transmissions')) {
            $this->affected = $this->matchesFence() ? 1 : 0;
            if ($this->affected === 1) $this->pdo->transmission = null;
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->query, 'FROM rooms')) {
            return ['id' => 7, 'code' => 'ROOM1234', 'created_by_user_id' => 7,
                'last_activity_at' => 'now', 'created_at' => 'now'];
        }
        if (str_contains($this->query, 'FROM users WHERE id')) {
            return ['id' => 7, 'display_name' => 'Josiel', 'email' => 'unit@example.invalid',
                'is_premium' => $this->pdo->premium ? 1 : 0, 'created_at' => 'now', 'updated_at' => 'now'];
        }
        if (str_starts_with($this->query, 'SELECT transmission.room_id')) {
            if ($this->pdo->replaceBeforeFind && $this->pdo->transmission !== null) {
                $this->pdo->replaceBeforeFind = false;
                $this->pdo->transmission['instance_id'] = str_repeat('b', 32);
                ++$this->pdo->transmission['revision'];
            }
            return $this->pdo->transmission ?? false;
        }
        return false;
    }
    public function rowCount(): int { return $this->affected; }
    private function matchesFence(): bool
    {
        $current = $this->pdo->transmission;
        return $current !== null && $current['source_type'] === 'screen'
            && $current['media_mode'] === 'live' && $current['owner_user_id'] === ($this->params['owner_user_id'] ?? null)
            && $current['instance_id'] === ($this->params['transmission_instance_id'] ?? null)
            && $current['revision'] === (int) ($this->params['transmission_revision'] ?? 0);
    }
}
