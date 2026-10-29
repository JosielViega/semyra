<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\RoomCommunicationController;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Repositories\RoomMessageRepository;
use App\Repositories\RoomParticipantRepository;
use App\Repositories\RoomRepository;
use App\Repositories\UserRepository;
use App\Services\AuthSession;
use App\Services\LiveKitRoomContext;
use App\Services\LiveKitVoiceTokenService;
use App\Services\RoomChatRateLimiter;
use App\Services\RoomParticipantSession;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class RoomCommunicationControllerTest extends TestCase
{
    private const SECRET = 'DUMMY_COMMUNICATION_SECRET_NOT_REAL_0123456789ABCDEF';

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testGuestCanSendSafePersistentMessageUsingServerIdentity(): void
    {
        [$controller, $pdo] = $this->controller(body: [
            'message' => '<img src=x onerror=alert(1)>',
            'client_message_id' => str_repeat('a', 32),
            'display_name' => 'Forged',
        ]);

        $response = $controller->send('ROOM1234');
        $payload = json_decode($response->body(), true);

        self::assertSame(201, $response->status());
        self::assertSame('Pedro', $payload['message']['sender_name']);
        self::assertSame('<img src=x onerror=alert(1)>', $payload['message']['message']);
        self::assertTrue($payload['message']['is_you']);
        self::assertSame('Pedro', $pdo->messages[0]['display_name']);
        self::assertArrayNotHasKey('Forged', $pdo->messages[0]);
        self::assertSame('no-store', $response->headers()['Cache-Control']);
    }

    public function testSqlInjectionPayloadIsStoredAsPlainMessage(): void
    {
        $message = "'; DROP TABLE room_messages; --";
        [$controller, $pdo] = $this->controller(body: [
            'message' => $message,
            'client_message_id' => str_repeat('9', 32),
        ]);

        $response = $controller->send('ROOM1234');

        self::assertSame(201, $response->status());
        self::assertCount(1, $pdo->messages);
        self::assertSame($message, $pdo->messages[0]['message']);
    }

    public function testMessageValidationRejectsEmptyTooLongAndControlCharacters(): void
    {
        foreach (['  ', str_repeat('x', 1001), "hello\0world"] as $message) {
            [$controller, $pdo] = $this->controller(body: [
                'message' => $message,
                'client_message_id' => str_repeat('b', 32),
            ]);
            self::assertSame(422, $controller->send('ROOM1234')->status());
            self::assertSame([], $pdo->messages);
        }
    }

    public function testSendRequiresCsrfRoomAndJoinedIdentity(): void
    {
        [$invalidCsrf] = $this->controller(validCsrf: false, body: $this->validBody());
        $invalidCsrfResponse = $invalidCsrf->send('ROOM1234');
        [$missingRoom] = $this->controller(roomExists: false, body: $this->validBody());
        $missingRoomResponse = $missingRoom->send('ROOM1234');
        [$notJoined] = $this->controller(joined: false, body: $this->validBody());
        $notJoinedResponse = $notJoined->send('ROOM1234');

        self::assertSame(419, $invalidCsrfResponse->status());
        self::assertSame(404, $missingRoomResponse->status());
        self::assertSame(403, $notJoinedResponse->status());
    }

    public function testClientMessageIdMakesRetryIdempotent(): void
    {
        [$first, $pdo, $session, $csrf] = $this->controller(body: $this->validBody());
        self::assertSame(201, $first->send('ROOM1234')->status());
        $retry = $this->buildController($pdo, $session, $csrf, body: $this->validBody());
        $response = $retry->send('ROOM1234');

        self::assertSame(200, $response->status());
        self::assertTrue(json_decode($response->body(), true)['duplicate']);
        self::assertCount(1, $pdo->messages);
    }

    public function testRateLimitRejectsSeventhMessage(): void
    {
        [$controller, $pdo, $session, $csrf] = $this->controller(body: $this->validBody());
        for ($index = 1; $index <= 6; ++$index) {
            $controller = $this->buildController($pdo, $session, $csrf, body: [
                'message' => 'message-' . $index,
                'client_message_id' => str_pad(dechex($index), 32, '0', STR_PAD_LEFT),
            ]);
            self::assertSame(201, $controller->send('ROOM1234')->status());
        }
        $controller = $this->buildController($pdo, $session, $csrf, body: [
            'message' => 'message-7',
            'client_message_id' => str_repeat('f', 32),
        ]);
        $response = $controller->send('ROOM1234');

        self::assertSame(429, $response->status());
        self::assertSame('3', $response->headers()['Retry-After']);
        self::assertCount(6, $pdo->messages);
    }

    public function testHistorySupportsRecentIncrementalAndOlderQueriesInChronologicalOrder(): void
    {
        [$controller, $pdo, $session, $csrf] = $this->controller();
        for ($index = 1; $index <= 5; ++$index) {
            $pdo->addMessage('message-' . $index, 'Pedro', str_repeat('a', 64), null);
        }

        $recent = $this->buildController($pdo, $session, $csrf, query: ['limit' => '3'])->messages('ROOM1234');
        $after = $this->buildController($pdo, $session, $csrf, query: ['after_id' => '3'])->messages('ROOM1234');
        $before = $this->buildController($pdo, $session, $csrf, query: ['before_id' => '4', 'limit' => '2'])->messages('ROOM1234');

        self::assertSame([3, 4, 5], array_column(json_decode($recent->body(), true)['messages'], 'id'));
        self::assertSame([4, 5], array_column(json_decode($after->body(), true)['messages'], 'id'));
        self::assertSame([2, 3], array_column(json_decode($before->body(), true)['messages'], 'id'));
    }

    public function testHistoryRejectsInvalidOrConflictingCursor(): void
    {
        [$controller, $pdo, $session, $csrf] = $this->controller();
        $invalid = $this->buildController($pdo, $session, $csrf, query: ['after_id' => '0']);
        $conflict = $this->buildController($pdo, $session, $csrf, query: ['after_id' => '1', 'before_id' => '2']);

        self::assertSame(422, $invalid->messages('ROOM1234')->status());
        self::assertSame(422, $conflict->messages('ROOM1234')->status());
    }

    public function testHistoryRequiresJoinedIdentity(): void
    {
        [$controller] = $this->controller(joined: false);

        self::assertSame(403, $controller->messages('ROOM1234')->status());
    }

    public function testAuthenticatedMessageUsesCurrentAccountName(): void
    {
        [$controller, $pdo] = $this->controller(authUserId: 7, users: [7 => 'Josiel'], body: $this->validBody());
        $payload = json_decode($controller->send('ROOM1234')->body(), true);

        self::assertSame('Josiel', $payload['message']['sender_name']);
        self::assertSame(7, $pdo->messages[0]['user_id']);
    }

    public function testVoiceTokenDoesNotRequireTransmissionAndOnlyAllowsMicrophone(): void
    {
        [$controller] = $this->controller(body: ['voice_instance_id' => str_repeat('c', 32)]);
        $response = $controller->voiceToken('ROOM1234');
        $payload = json_decode($response->body(), true);
        $claims = JWT::decode($payload['participant_token'], new Key(self::SECRET, 'HS256'));

        self::assertSame(201, $response->status());
        self::assertMatchesRegularExpression('/^smy_c_[a-f0-9]{32}$/', $payload['participant_identity']);
        self::assertTrue($claims->video->canPublish);
        self::assertSame(['microphone'], $claims->video->canPublishSources);
        self::assertFalse($claims->video->canPublishData);
    }

    public function testVoiceTokenRequiresCsrfAndJoinedIdentity(): void
    {
        [$invalidCsrf] = $this->controller(
            validCsrf: false,
            body: ['voice_instance_id' => str_repeat('e', 32)],
        );
        [$notJoined] = $this->controller(
            joined: false,
            body: ['voice_instance_id' => str_repeat('e', 32)],
        );

        self::assertSame(419, $invalidCsrf->voiceToken('ROOM1234')->status());
        self::assertSame(403, $notJoined->voiceToken('ROOM1234')->status());
    }

    /** @return array{RoomCommunicationController, CommunicationPdo, Session, Csrf} */
    private function controller(
        bool $validCsrf = true,
        bool $roomExists = true,
        bool $joined = true,
        array $body = [],
        array $query = [],
        ?int $authUserId = null,
        array $users = [],
    ): array {
        $_SESSION = [];
        $pdo = new CommunicationPdo($users);
        $pdo->roomExists = $roomExists;
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $pdo);
        $session = new Session(false);
        $csrf = new Csrf($session);
        $token = $csrf->token();
        $participantSession = new RoomParticipantSession($session);
        if ($joined) $participantSession->rememberGuest('ROOM1234', 'Pedro');
        if ($authUserId !== null) (new AuthSession($session))->login($authUserId);
        $body['_token'] = $validCsrf ? $token : 'invalid';

        return [$this->buildController($pdo, $session, $csrf, $body, $query), $pdo, $session, $csrf];
    }

    private function buildController(
        CommunicationPdo $pdo,
        Session $session,
        Csrf $csrf,
        array $body = [],
        array $query = [],
    ): RoomCommunicationController {
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $pdo);
        if (!array_key_exists('_token', $body)) $body['_token'] = $csrf->token();

        return new RoomCommunicationController(
            new Request($query, $body),
            $csrf,
            new RoomRepository($database),
            new RoomParticipantRepository($database),
            new RoomMessageRepository($database),
            new RoomParticipantSession($session),
            new UserRepository($database),
            new AuthSession($session),
            new RoomChatRateLimiter($session),
            new LiveKitRoomContext('testing'),
            new LiveKitVoiceTokenService([
                'enabled' => true, 'url' => 'wss://unit-test.invalid',
                'api_key' => 'DUMMY_KEY', 'api_secret' => self::SECRET,
                'token_ttl_seconds' => 600,
            ]),
        );
    }

    private function validBody(): array
    {
        return ['message' => 'Hello', 'client_message_id' => str_repeat('d', 32)];
    }
}

final class CommunicationPdo extends PDO
{
    public bool $roomExists = true;
    /** @var list<array<string, mixed>> */
    public array $messages = [];
    /** @param array<int, string> $users */
    public function __construct(public array $users = []) {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new CommunicationStatement($this, $query);
    }
    public function addMessage(string $message, string $name, string $hash, ?int $userId, ?string $clientId = null): array
    {
        $record = [
            'id' => count($this->messages) + 1, 'room_id' => 7,
            'participant_key_hash' => $hash, 'user_id' => $userId,
            'display_name' => $name, 'message' => $message,
            'client_message_id' => $clientId ?? str_pad((string) (count($this->messages) + 1), 32, '0', STR_PAD_LEFT),
            'created_at' => sprintf('2026-10-29 12:00:%02d.000', count($this->messages) + 1),
        ];
        $this->messages[] = $record;
        return $record;
    }
}

final class CommunicationStatement extends PDOStatement
{
    private array $params = [];
    public function __construct(private readonly CommunicationPdo $pdo, private readonly string $query) {}
    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (str_contains($this->query, 'INSERT INTO room_messages')) {
            $this->pdo->addMessage(
                (string) $this->params['message'], (string) $this->params['display_name'],
                (string) $this->params['participant_key_hash'],
                $this->params['user_id'] === null ? null : (int) $this->params['user_id'],
                (string) $this->params['client_message_id'],
            );
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->query, 'FROM rooms')) {
            return $this->pdo->roomExists ? [
                'id' => 7, 'code' => 'ROOM1234', 'created_by_user_id' => null,
                'last_activity_at' => '2026-10-29 12:00:00.000', 'created_at' => '2026-10-29 12:00:00.000',
            ] : false;
        }
        if (str_contains($this->query, 'FROM users')) {
            $id = (int) ($this->params['id'] ?? 0);
            if (!isset($this->pdo->users[$id])) return false;
            return [
                'id' => $id, 'display_name' => $this->pdo->users[$id],
                'email' => 'user@example.test', 'is_premium' => 0,
                'created_at' => '2026-10-29 12:00:00', 'updated_at' => '2026-10-29 12:00:00',
            ];
        }
        if (str_contains($this->query, 'FROM room_messages') && isset($this->params['client_message_id'])) {
            foreach ($this->pdo->messages as $message) {
                if ($message['room_id'] === (int) $this->params['room_id']
                    && hash_equals($message['participant_key_hash'], (string) $this->params['participant_key_hash'])
                    && hash_equals($message['client_message_id'], (string) $this->params['client_message_id'])) return $message;
            }
        }
        return false;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $messages = array_values(array_filter(
            $this->pdo->messages,
            fn (array $message): bool => $message['room_id'] === (int) ($this->params['room_id'] ?? 0),
        ));
        if (isset($this->params['after_id'])) {
            $messages = array_values(array_filter($messages, fn (array $message): bool => $message['id'] > (int) $this->params['after_id']));
        }
        if (isset($this->params['before_id'])) {
            $messages = array_values(array_filter($messages, fn (array $message): bool => $message['id'] < (int) $this->params['before_id']));
        }
        preg_match('/LIMIT (\d+)/', $this->query, $match);
        $limit = isset($match[1]) ? (int) $match[1] : 50;
        if (str_contains($this->query, 'ORDER BY id DESC LIMIT')) $messages = array_slice($messages, -$limit);
        else $messages = array_slice($messages, 0, $limit);
        usort($messages, fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        return $messages;
    }
    public function rowCount(): int { return 1; }
}
