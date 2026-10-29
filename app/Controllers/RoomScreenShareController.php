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
use App\Services\DesktopHostSessionService;
use App\Services\PremiumAccess;
use App\Services\RoomParticipantSession;
use App\Services\RoomTransmissionPresenter;
use Throwable;

final class RoomScreenShareController
{
    private const RESPONSE_HEADERS = [
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
        private readonly PremiumAccess $premiumAccess,
        private readonly RoomTransmissionPresenter $presenter,
        private readonly DesktopHostSessionService $hostSessions,
    ) {
    }

    public function start(string $code): Response
    {
        if (!$this->csrf->verify($this->request->input('_token'))) {
            return $this->error('invalid_csrf', 419);
        }
        $room = $this->rooms->findByCode($code);
        if ($room === null) {
            return $this->error('room_not_found', 404);
        }
        $currentUser = $this->authenticatedUser();
        if ($currentUser === null) {
            return $this->error('authentication_required', 403);
        }
        $identity = $this->accountIdentity($room['code'], $currentUser);
        if ($identity === null) {
            return $this->error('join_required', 403);
        }
        if (!$this->premiumAccess->canShareScreen($currentUser)) {
            return $this->error('premium_required', 403);
        }
        $roomId = (int) $room['id'];
        $participantKeyHash = hash('sha256', $identity['participant_key']);
        $userId = (int) $currentUser['id'];
        $startedInstanceId = $this->transmissions->startOrReplace(
            $roomId,
            $participantKeyHash,
            'screen',
            null,
            'live',
            $userId,
        );
        $current = $this->transmissions->findByRoom($roomId);
        if ($current === null
            || ($current['source_type'] ?? null) !== 'screen'
            || ($current['instance_id'] ?? null) !== $startedInstanceId) {
            return $this->error('transmission_changed', 409);
        }

        $instanceId = $startedInstanceId;
        $revision = (int) $current['revision'];
        try {
            $hostSession = $this->hostSessions->issue(
                $roomId,
                $instanceId,
                $revision,
                $userId,
                $participantKeyHash,
                DesktopHostSessionService::SCREEN_PERMISSION,
            );
            if ($hostSession === null) {
                throw new \RuntimeException('Transmission changed while issuing host session.');
            }
        } catch (Throwable) {
            $this->transmissions->endScreen(
                $roomId,
                $participantKeyHash,
                $instanceId,
                $revision,
                $userId,
            );
            return $this->error('host_session_failed', 503);
        }

        return Response::json([
            'transmission' => $this->presenter->present($current, $participantKeyHash, $userId),
            'host_session_token' => $hostSession['token'],
            'expires_at' => $hostSession['expires_at'],
            'permission' => $hostSession['permission'],
        ], 201, self::RESPONSE_HEADERS);
    }

    public function heartbeat(string $code): Response
    {
        $context = $this->ownerContext($code);
        if ($context instanceof Response) {
            return $context;
        }
        [$roomId, $participantKeyHash, $userId, $instanceId, $revision] = $context;
        if (!$this->transmissions->touchScreenPublisher(
            $roomId,
            $participantKeyHash,
            $instanceId,
            $revision,
            $userId,
        )) {
            return $this->error('transmission_changed', 409);
        }

        return Response::json(['ok' => true], 200, self::RESPONSE_HEADERS);
    }

    public function stop(string $code): Response
    {
        $context = $this->ownerContext($code);
        if ($context instanceof Response) {
            return $context;
        }
        [$roomId, $participantKeyHash, $userId, $instanceId, $revision] = $context;
        if (!$this->transmissions->endScreen(
            $roomId,
            $participantKeyHash,
            $instanceId,
            $revision,
            $userId,
        )) {
            return $this->error('transmission_changed', 409);
        }

        return Response::json(['ok' => true], 200, self::RESPONSE_HEADERS);
    }

    /** @return Response|array{int, string, int, string, int} */
    private function ownerContext(string $code): Response|array
    {
        if (!$this->csrf->verify($this->request->input('_token'))) {
            return $this->error('invalid_csrf', 419);
        }
        $room = $this->rooms->findByCode($code);
        if ($room === null) {
            return $this->error('room_not_found', 404);
        }
        $currentUser = $this->authenticatedUser();
        if ($currentUser === null) {
            return $this->error('authentication_required', 403);
        }
        $identity = $this->accountIdentity($room['code'], $currentUser);
        if ($identity === null) {
            return $this->error('join_required', 403);
        }
        $instanceId = $this->instanceId($this->request->input('transmission_instance_id'));
        $revision = $this->positiveInteger($this->request->input('transmission_revision'));
        if ($instanceId === null || $revision === null) {
            return $this->error('invalid_request', 422);
        }

        return [
            (int) $room['id'],
            hash('sha256', $identity['participant_key']),
            (int) $currentUser['id'],
            $instanceId,
            $revision,
        ];
    }

    /** @param array<string, mixed> $currentUser */
    private function accountIdentity(string $roomCode, array $currentUser): ?array
    {
        $identity = $this->participantSession->identityFor($roomCode);
        if ($identity === null
            || ($identity['user_id'] !== null && $identity['user_id'] !== $currentUser['id'])) {
            return null;
        }

        return $this->participantSession->rememberAccount(
            $roomCode,
            (int) $currentUser['id'],
            (string) $currentUser['display_name'],
        );
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

    private function instanceId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{32}$/', $value) === 1 ? $value : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($parsed) ? $parsed : null;
    }

    private function error(string $error, int $status): Response
    {
        return Response::json(['error' => $error], $status, self::RESPONSE_HEADERS);
    }
}
