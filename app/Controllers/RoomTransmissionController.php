<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\RoomRepository;
use App\Repositories\RoomTransmissionRepository;
use App\Repositories\UserRepository;
use App\Services\AuthSession;
use App\Services\RoomParticipantSession;
use App\Services\RoomTransmissionPlayback;
use App\Services\RoomTransmissionPresenter;
use App\Services\YouTubeUrlParser;

final class RoomTransmissionController
{
    private const MAX_URL_LENGTH = 2048;

    public function __construct(
        private readonly Request $request,
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly RoomRepository $rooms,
        private readonly RoomTransmissionRepository $transmissions,
        private readonly RoomParticipantSession $participantSession,
        private readonly YouTubeUrlParser $youtubeUrlParser,
        private readonly RoomTransmissionPlayback $playback,
        private readonly RoomTransmissionPresenter $transmissionPresenter,
        private readonly UserRepository $users,
        private readonly AuthSession $auth,
    ) {
    }

    public function start(string $code): Response
    {
        if (!$this->csrf->verify($this->request->input('_token'))) {
            return $this->errorResponse('Formulário inválido ou expirado', 'Recarregue a página e tente novamente.', 419);
        }

        $room = $this->rooms->findByCode($code);
        if ($room === null) {
            return $this->errorResponse('Sala não encontrada', 'Não existe uma sala com o código informado.', 404);
        }

        $identity = $this->participantSession->identityFor($room['code']);
        if ($identity === null) {
            return $this->errorResponse('Entrada necessária', 'Entre na sala antes de iniciar uma transmissão.', 403);
        }

        $currentUser = $this->authenticatedUser();
        $identity = $this->identityForCurrentAccount($room['code'], $identity, $currentUser);
        if ($identity === null) {
            return $this->errorResponse('Entrada necessária', 'Entre na sala antes de iniciar uma transmissão.', 403);
        }

        $url = $this->request->input('youtube_url');
        $videoId = is_string($url) && strlen($url) <= self::MAX_URL_LENGTH
            ? $this->youtubeUrlParser->parse($url)
            : null;
        if ($videoId === null) {
            $this->session->flash('error', 'Informe uma URL válida de vídeo ou Live do YouTube.');
            return Response::redirect('/room/' . $room['code'], 303);
        }

        $mediaMode = $this->request->input('media_mode');
        if (!is_string($mediaMode) || !in_array($mediaMode, ['vod', 'live'], true)) {
            $this->session->flash('error', 'Escolha se o conteúdo é um vídeo ou uma transmissão ao vivo.');
            return Response::redirect('/room/' . $room['code'], 303);
        }

        $this->transmissions->startOrReplace(
            (int) $room['id'],
            hash('sha256', $identity['participant_key']),
            'youtube',
            $videoId,
            $mediaMode,
            $currentUser['id'] ?? null,
        );
        $this->session->flash('success', 'Transmissão iniciada.');

        return Response::redirect('/room/' . $room['code'], 303);
    }

    public function startIptv(string $code): Response
    {
        if (!$this->csrf->verify($this->request->input('_token'))) {
            return Response::json(['error' => 'invalid_csrf'], 419, ['Cache-Control' => 'no-store']);
        }
        $room = $this->rooms->findByCode($code);
        if ($room === null) {
            return Response::json(['error' => 'room_not_found'], 404, ['Cache-Control' => 'no-store']);
        }
        $currentUser = $this->authenticatedUser();
        if ($currentUser === null) {
            return Response::json(['error' => 'authentication_required'], 403, ['Cache-Control' => 'no-store']);
        }
        $identity = $this->participantSession->identityFor($room['code']);
        if ($identity === null) {
            return Response::json(['error' => 'join_required'], 403, ['Cache-Control' => 'no-store']);
        }
        $identity = $this->identityForCurrentAccount($room['code'], $identity, $currentUser);
        if ($identity === null) {
            return Response::json(['error' => 'join_required'], 403, ['Cache-Control' => 'no-store']);
        }
        $participantKeyHash = hash('sha256', $identity['participant_key']);
        $this->transmissions->startOrReplace(
            (int) $room['id'],
            $participantKeyHash,
            'iptv',
            null,
            'live',
            $currentUser['id'] ?? null,
        );
        $current = $this->transmissions->findByRoom((int) $room['id']);
        if ($current === null) {
            return Response::json(['error' => 'transmission_start_failed'], 503, ['Cache-Control' => 'no-store']);
        }
        return Response::json([
            'transmission' => $this->transmissionPresenter->present(
                $current,
                $participantKeyHash,
                $currentUser['id'] ?? null,
            ),
        ], 201, ['Cache-Control' => 'no-store']);
    }

    public function end(string $code): Response
    {
        if (!$this->csrf->verify($this->request->input('_token'))) {
            return $this->errorResponse('Formulário inválido ou expirado', 'Recarregue a página e tente novamente.', 419);
        }

        $room = $this->rooms->findByCode($code);
        if ($room === null) {
            return $this->errorResponse('Sala não encontrada', 'Não existe uma sala com o código informado.', 404);
        }

        $identity = $this->participantSession->identityFor($room['code']);
        if ($identity === null) {
            return $this->errorResponse('Entrada necessária', 'Entre na sala antes de encerrar uma transmissão.', 403);
        }


        $currentUser = $this->authenticatedUser();
        $identity = $this->identityForCurrentAccount($room['code'], $identity, $currentUser);
        if ($identity === null) {
            return $this->errorResponse('Entrada necessária', 'Entre na sala antes de encerrar uma transmissão.', 403);
        }

        $participantKeyHash = hash('sha256', $identity['participant_key']);
        $transmission = $this->transmissions->findByRoom((int) $room['id']);
        if ($transmission === null) {
            return $this->errorResponse('Transmissão alterada', 'A transmissão já foi encerrada ou substituída.', 409);
        }
        if (!$this->ownsTransmission($transmission, $participantKeyHash, $currentUser['id'] ?? null)) {
            return $this->errorResponse(
                'Ação não permitida',
                'Somente quem iniciou a transmissão atual pode encerrá-la.',
                403,
            );
        }

        $instanceId = $this->instanceId($this->request->input('transmission_instance_id'));
        $revision = $this->positiveInteger($this->request->input('transmission_revision'));
        if ($instanceId === null || $revision === null) {
            return $this->errorResponse('Solicitação inválida', 'Recarregue a página e tente novamente.', 422);
        }
        if (!hash_equals((string) $transmission['instance_id'], $instanceId)
            || (int) $transmission['revision'] !== $revision) {
            return $this->errorResponse('Transmissão alterada', 'A transmissão foi substituída. Recarregue a página.', 409);
        }

        if (!$this->transmissions->end(
            (int) $room['id'],
            $participantKeyHash,
            $instanceId,
            $revision,
            $currentUser['id'] ?? null,
        )) {
            return $this->errorResponse(
                'Transmissão alterada',
                'A transmissão foi substituída. Recarregue a página.',
                409,
            );
        }

        $this->session->flash('success', 'Transmissão encerrada.');

        return Response::redirect('/room/' . $room['code'], 303);
    }

    public function playback(string $code): Response
    {
        if (!$this->csrf->verify($this->request->input('_token'))) {
            return Response::json(['error' => 'invalid_csrf'], 419);
        }

        $room = $this->rooms->findByCode($code);
        if ($room === null) {
            return Response::json(['error' => 'room_not_found'], 404);
        }

        $identity = $this->participantSession->identityFor($room['code']);
        if ($identity === null) {
            return Response::json(['error' => 'join_required'], 403);
        }


        $currentUser = $this->authenticatedUser();
        $identity = $this->identityForCurrentAccount($room['code'], $identity, $currentUser);
        if ($identity === null) {
            return Response::json(['error' => 'join_required'], 403);
        }

        $transmission = $this->transmissions->findByRoom((int) $room['id']);
        if ($transmission === null) {
            return Response::json(['error' => 'transmission_not_found'], 409);
        }

        $participantKeyHash = hash('sha256', $identity['participant_key']);
        if (!$this->ownsTransmission($transmission, $participantKeyHash, $currentUser['id'] ?? null)) {
            return Response::json(['error' => 'owner_required'], 403);
        }

        try {
            $command = $this->playback->normalizeCommand([
                'action' => $this->request->input('action'),
                'position_ms' => $this->request->input('position_ms'),
                'transmission_instance_id' => $this->request->input('transmission_instance_id'),
                'transmission_revision' => $this->request->input('transmission_revision'),
                'playback_revision' => $this->request->input('playback_revision'),
            ],
                (string) $transmission['playback_state'],
                (string) $transmission['media_mode'],
                (int) $transmission['playback_position_ms'],
            );
        } catch (\InvalidArgumentException) {
            return Response::json(['error' => 'invalid_playback_command'], 422);
        }

        $updated = $this->transmissions->updatePlayback(
            (int) $room['id'],
            $participantKeyHash,
            $command['transmission_instance_id'],
            $command['transmission_revision'],
            $command['playback_revision'],
            $command['state'],
            $command['position_ms'],
            $command['at_live_edge'],
            $currentUser['id'] ?? null,
        );
        $current = $this->transmissions->findByRoom((int) $room['id']);
        if (!$updated || $current === null) {
            return Response::json([
                'error' => 'playback_conflict',
                'transmission' => $this->transmissionPresenter->present(
                    $current,
                    $participantKeyHash,
                    $currentUser['id'] ?? null,
                ),
            ], 409);
        }

        return Response::json([
            'transmission' => $this->transmissionPresenter->present(
                $current,
                $participantKeyHash,
                $currentUser['id'] ?? null,
            ),
        ]);
    }

    private function errorResponse(string $heading, string $message, int $status): Response
    {
        return Response::html($this->view->render('pages/error', [
            'title' => $heading,
            'heading' => $heading,
            'message' => $message,
        ]), $status);
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

    /** @param array<string, mixed> $transmission */
    private function ownsTransmission(array $transmission, string $participantKeyHash, ?int $currentUserId): bool
    {
        if (($transmission['owner_user_id'] ?? null) !== null) {
            return $currentUserId !== null && (int) $transmission['owner_user_id'] === $currentUserId;
        }

        return hash_equals((string) $transmission['owner_participant_key_hash'], $participantKeyHash);
    }

    private function instanceId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{32}$/', $value) === 1
            ? $value
            : null;
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
}
