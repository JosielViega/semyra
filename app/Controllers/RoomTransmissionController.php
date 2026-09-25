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

        $this->transmissions->startOrReplace(
            (int) $room['id'],
            hash('sha256', $identity['participant_key']),
            'youtube',
            $videoId,
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

    private function errorResponse(string $heading, string $message, int $status): Response
    {
        return Response::html($this->view->render('pages/error', [
            'title' => $heading,
            'heading' => $heading,
            'message' => $message,
        ]), $status);
    }
}
