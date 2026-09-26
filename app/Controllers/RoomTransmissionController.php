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
        );
        $this->session->flash('success', 'Transmissão iniciada.');

        return Response::redirect('/room/' . $room['code'], 303);
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

        $participantKeyHash = hash('sha256', $identity['participant_key']);
        if (!$this->transmissions->end((int) $room['id'], $participantKeyHash)) {
            return $this->errorResponse(
                'Ação não permitida',
                'Somente quem iniciou a transmissão atual pode encerrá-la.',
                403,
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

        $transmission = $this->transmissions->findByRoom((int) $room['id']);
        if ($transmission === null) {
            return Response::json(['error' => 'transmission_not_found'], 409);
        }

        $participantKeyHash = hash('sha256', $identity['participant_key']);
        if (!hash_equals((string) $transmission['owner_participant_key_hash'], $participantKeyHash)) {
            return Response::json(['error' => 'owner_required'], 403);
        }

        try {
            $command = $this->playback->normalizeCommand([
                'action' => $this->request->input('action'),
                'position_ms' => $this->request->input('position_ms'),
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
            $command['transmission_revision'],
            $command['playback_revision'],
            $command['state'],
            $command['position_ms'],
            $command['at_live_edge'],
        );
        $current = $this->transmissions->findByRoom((int) $room['id']);
        if (!$updated || $current === null) {
            return Response::json([
                'error' => 'playback_conflict',
                'transmission' => $this->transmissionPresenter->present($current, $participantKeyHash),
            ], 409);
        }

        return Response::json([
            'transmission' => $this->transmissionPresenter->present($current, $participantKeyHash),
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
}
