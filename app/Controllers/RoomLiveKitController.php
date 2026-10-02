<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\RoomRepository;
use App\Repositories\RoomTransmissionRepository;
use App\Repositories\UserRepository;
use App\Services\AuthSession;
use App\Services\LiveKitRoomContext;
use App\Services\LiveKitViewerTokenService;
use App\Services\RoomParticipantSession;
use Throwable;

final class RoomLiveKitController
{
    private const TOKEN_HEADERS = [
        'Cache-Control' => 'no-store',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private readonly Request $request,
        private readonly Csrf $csrf,
        private readonly RoomRepository $rooms,
        private readonly RoomTransmissionRepository $transmissions,
        private readonly RoomParticipantSession $participantSession,
        private readonly UserRepository $users,
        private readonly AuthSession $auth,
        private readonly LiveKitRoomContext $roomContext,
        private readonly LiveKitViewerTokenService $tokens,
    ) {
    }

    public function viewerToken(string $code): Response
    {
        if (!$this->csrf->verify($this->request->input('_token'))) {
            return $this->jsonError('invalid_csrf', 419);
        }

        $room = $this->rooms->findByCode($code);
        if ($room === null) {
            return $this->jsonError('room_not_found', 404);
        }

        $identity = $this->participantSession->identityFor($room['code']);
        if ($identity === null) {
            return $this->jsonError('join_required', 403);
        }

        $currentUser = $this->authenticatedUser();
        $identity = $this->identityForCurrentAccount($room['code'], $identity, $currentUser);
        if ($identity === null) {
            return $this->jsonError('join_required', 403);
        }

        $transmission = $this->transmissions->findByRoom((int) $room['id']);
        if ($transmission === null) {
            return $this->jsonError('transmission_not_found', 409);
        }

        if (($transmission['source_type'] ?? null) !== 'iptv'
            || ($transmission['media_mode'] ?? null) !== 'live') {
            return $this->jsonError('livekit_not_applicable', 409);
        }

        $requestedInstanceId = $this->instanceId($this->request->input('transmission_instance_id'));
        if ($requestedInstanceId === null) {
            return $this->jsonError('invalid_transmission_instance_id', 422);
        }
        $requestedRevision = $this->positiveInteger($this->request->input('transmission_revision'));
        if ($requestedRevision === null) {
            return $this->jsonError('invalid_transmission_revision', 422);
        }
        if (!hash_equals((string) ($transmission['instance_id'] ?? ''), $requestedInstanceId)
            || (int) ($transmission['revision'] ?? 0) !== $requestedRevision) {
            return $this->jsonError('transmission_changed', 409);
        }

        if (!$this->tokens->isAvailable()) {
            return $this->jsonError('livekit_unavailable', 503);
        }

        try {
            $roomId = (int) $room['id'];
            $viewerIdentity = $this->roomContext->viewerIdentity();
            $participantToken = $this->tokens->issue(
                $this->roomContext->roomName($roomId),
                $viewerIdentity,
            );

            return Response::json([
                'server_url' => $this->tokens->serverUrl(),
                'participant_token' => $participantToken,
                'transmission_instance_id' => $requestedInstanceId,
                'transmission_revision' => $requestedRevision,
                'publisher_identity' => $this->roomContext->publisherIdentity(
                    $roomId,
                    $requestedInstanceId,
                ),
            ], 201, self::TOKEN_HEADERS);
        } catch (Throwable) {
            return $this->jsonError('livekit_token_failed', 503);
        }
    }

    private function jsonError(string $error, int $status): Response
    {
        return Response::json(['error' => $error], $status, self::TOKEN_HEADERS);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($parsed) ? $parsed : null;
    }

    private function instanceId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{32}$/', $value) === 1
            ? $value
            : null;
    }

    /** @return null|array{id: int, display_name: string, email: string, created_at: string, updated_at: string} */
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

    /**
     * @param array{participant_key: string, display_name: string, user_id: null|int} $identity
     * @param null|array{id: int, display_name: string, email: string, created_at: string, updated_at: string} $currentUser
     * @return null|array{participant_key: string, display_name: string, user_id: null|int}
     */
    private function identityForCurrentAccount(string $roomCode, array $identity, ?array $currentUser): ?array
    {
        if ($currentUser === null) {
            return $identity;
        }
        if ($identity['user_id'] !== null && $identity['user_id'] !== $currentUser['id']) {
            return null;
        }

        return $this->participantSession->rememberAccount(
            $roomCode,
            $currentUser['id'],
            $currentUser['display_name'],
        );
    }
}
