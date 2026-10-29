<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\RoomMessageRepository;
use App\Repositories\RoomParticipantRepository;
use App\Repositories\RoomRepository;
use App\Repositories\UserRepository;
use App\Services\AuthSession;
use App\Services\LiveKitRoomContext;
use App\Services\LiveKitVoiceTokenService;
use App\Services\RoomChatRateLimiter;
use App\Services\RoomParticipantSession;
use Throwable;

final class RoomCommunicationController
{
    private const HEADERS = [
        'Cache-Control' => 'no-store',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private readonly Request $request,
        private readonly Csrf $csrf,
        private readonly RoomRepository $rooms,
        private readonly RoomParticipantRepository $participants,
        private readonly RoomMessageRepository $messages,
        private readonly RoomParticipantSession $participantSession,
        private readonly UserRepository $users,
        private readonly AuthSession $auth,
        private readonly RoomChatRateLimiter $rateLimiter,
        private readonly LiveKitRoomContext $liveKitContext,
        private readonly LiveKitVoiceTokenService $voiceTokens,
    ) {
    }

    public function messages(string $code): Response
    {
        $context = $this->context($code);
        if ($context instanceof Response) {
            return $context;
        }
        [$room, $identity, $participantKeyHash, $currentUser] = $context;
        $this->touchParticipant($room, $identity, $participantKeyHash);

        $afterId = $this->cursor($this->request->query('after_id'));
        $beforeId = $this->cursor($this->request->query('before_id'));
        if ($afterId === false || $beforeId === false || ($afterId !== null && $beforeId !== null)) {
            return $this->error('invalid_cursor', 422);
        }
        $limit = $this->limit($this->request->query('limit'));
        if ($limit === null) {
            return $this->error('invalid_limit', 422);
        }
        $roomId = (int) $room['id'];
        $records = $afterId !== null
            ? $this->messages->after($roomId, $afterId, $limit)
            : ($beforeId !== null
                ? $this->messages->before($roomId, $beforeId, $limit)
                : $this->messages->recent($roomId, $limit));

        return Response::json([
            'messages' => array_map(
                fn (array $record): array => $this->present($record, $participantKeyHash, $currentUser['id'] ?? null),
                $records,
            ),
            'has_more' => count($records) === $limit,
        ], 200, self::HEADERS);
    }

    public function send(string $code): Response
    {
        if (!$this->csrf->verify($this->request->input('_token'))) {
            return $this->error('invalid_csrf', 419);
        }
        $context = $this->context($code);
        if ($context instanceof Response) {
            return $context;
        }
        [$room, $identity, $participantKeyHash, $currentUser] = $context;
        $message = $this->normalizeMessage($this->request->input('message'));
        if ($message === null) {
            return $this->error('invalid_message', 422);
        }
        $clientMessageId = $this->request->input('client_message_id');
        if (!is_string($clientMessageId) || preg_match('/^[a-f0-9]{32}$/', $clientMessageId) !== 1) {
            return $this->error('invalid_client_message_id', 422);
        }
        $roomId = (int) $room['id'];
        $existing = $this->messages->findByClientId($roomId, $participantKeyHash, $clientMessageId);
        if ($existing !== null) {
            return Response::json([
                'message' => $this->present($existing, $participantKeyHash, $currentUser['id'] ?? null),
                'duplicate' => true,
            ], 200, self::HEADERS);
        }
        if (!$this->rateLimiter->allow($room['code'], $participantKeyHash, $message)) {
            return $this->error('rate_limited', 429)->withHeader('Retry-After', '3');
        }
        $this->touchParticipant($room, $identity, $participantKeyHash);
        $record = $this->messages->create(
            $roomId,
            $participantKeyHash,
            $identity['user_id'],
            $identity['display_name'],
            $clientMessageId,
            $message,
        );
        $this->rooms->touchActivity($roomId);

        return Response::json([
            'message' => $this->present($record, $participantKeyHash, $currentUser['id'] ?? null),
            'duplicate' => false,
        ], 201, self::HEADERS);
    }

    public function voiceToken(string $code): Response
    {
        if (!$this->csrf->verify($this->request->input('_token'))) {
            return $this->error('invalid_csrf', 419);
        }
        $context = $this->context($code);
        if ($context instanceof Response) {
            return $context;
        }
        [$room, $identity, $participantKeyHash] = $context;
        $voiceInstanceId = $this->request->input('voice_instance_id');
        if (!is_string($voiceInstanceId) || preg_match('/^[a-f0-9]{32}$/', $voiceInstanceId) !== 1) {
            return $this->error('invalid_voice_instance_id', 422);
        }
        if (!$this->voiceTokens->isAvailable()) {
            return $this->error('livekit_unavailable', 503);
        }
        $this->touchParticipant($room, $identity, $participantKeyHash);
        try {
            $identityValue = $this->liveKitContext->voiceIdentity(
                (int) $room['id'],
                $participantKeyHash,
                $voiceInstanceId,
            );

            return Response::json([
                'server_url' => $this->voiceTokens->serverUrl(),
                'participant_token' => $this->voiceTokens->issue(
                    $this->liveKitContext->roomName((int) $room['id']),
                    $identityValue,
                    $identity['display_name'],
                ),
                'participant_identity' => $identityValue,
            ], 201, self::HEADERS);
        } catch (Throwable) {
            return $this->error('livekit_token_failed', 503);
        }
    }

    /** @return Response|array{array<string, mixed>, array{participant_key: string, display_name: string, user_id: null|int}, string, null|array<string, mixed>} */
    private function context(string $code): Response|array
    {
        $room = $this->rooms->findByCode($code);
        if ($room === null) {
            return $this->error('room_not_found', 404);
        }
        $identity = $this->participantSession->identityFor($room['code']);
        if ($identity === null) {
            return $this->error('join_required', 403);
        }
        $currentUser = $this->authenticatedUser();
        if ($currentUser !== null) {
            if ($identity['user_id'] !== null && $identity['user_id'] !== $currentUser['id']) {
                return $this->error('join_required', 403);
            }
            $identity = $this->participantSession->rememberAccount(
                $room['code'],
                $currentUser['id'],
                $currentUser['display_name'],
            );
        }

        return [$room, $identity, hash('sha256', $identity['participant_key']), $currentUser];
    }

    /** @param array<string, mixed> $room @param array{participant_key: string, display_name: string, user_id: null|int} $identity */
    private function touchParticipant(array $room, array $identity, string $participantKeyHash): void
    {
        $this->participants->touch(
            (int) $room['id'],
            $participantKeyHash,
            $identity['display_name'],
            null,
            $identity['user_id'],
        );
    }

    private function normalizeMessage(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $message = trim(str_replace("\r\n", "\n", str_replace("\r", "\n", $value)));
        $length = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
        if ($message === '' || $length > 1000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $message) === 1) {
            return null;
        }

        return $message;
    }

    private function cursor(mixed $value): int|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return false;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($parsed) ? $parsed : false;
    }

    private function limit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return 50;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);

        return is_int($parsed) ? $parsed : null;
    }

    /** @param array<string, mixed> $record @return array{id: int, sender_name: string, message: string, created_at: string, is_you: bool} */
    private function present(array $record, string $participantKeyHash, ?int $userId): array
    {
        $recordUserId = $record['user_id'] ?? null;
        return [
            'id' => (int) $record['id'],
            'sender_name' => (string) $record['display_name'],
            'message' => (string) $record['message'],
            'created_at' => (string) $record['created_at'],
            'is_you' => $recordUserId !== null && $userId !== null
                ? (int) $recordUserId === $userId
                : hash_equals((string) $record['participant_key_hash'], $participantKeyHash),
        ];
    }

    private function error(string $error, int $status): Response
    {
        return Response::json(['error' => $error], $status, self::HEADERS);
    }

    /** @return null|array<string, mixed> */
    private function authenticatedUser(): ?array
    {
        $userId = $this->auth->userId();
        if ($userId === null) {
            return null;
        }
        $user = $this->users->findById($userId);
        if ($user === null) {
            $this->auth->logout();
            return null;
        }

        return $user;
    }
}
