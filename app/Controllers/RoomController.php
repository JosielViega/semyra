<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\RoomRepository;
use App\Services\RoomCodeGenerator;
use App\Services\YouTubeUrlParser;

final class RoomController
{
    private const MAX_URL_LENGTH = 2048;
    private const MAX_CODE_ATTEMPTS = 5;

    public function __construct(
        private readonly Request $request,
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly RoomRepository $rooms,
        private readonly RoomCodeGenerator $codeGenerator,
        private readonly YouTubeUrlParser $youtubeUrlParser,
    ) {
    }

    public function store(): Response
    {
        if (!$this->csrf->verify($this->request->input('_token'))) {
            return Response::html($this->view->render('pages/error', [
                'title' => 'Solicitação inválida',
                'heading' => 'Formulário inválido ou expirado',
                'message' => 'Recarregue a página e tente novamente.',
            ]), 419);
        }

        $url = $this->request->input('youtube_url');
        $videoId = is_string($url) && strlen($url) <= self::MAX_URL_LENGTH
            ? $this->youtubeUrlParser->parse($url)
            : null;

        if ($videoId === null) {
            $this->session->flash('error', 'Informe uma URL válida de vídeo ou Live do YouTube.');
            return Response::redirect('/');
        }

        for ($attempt = 0; $attempt < self::MAX_CODE_ATTEMPTS; ++$attempt) {
            $code = $this->codeGenerator->generate();
            if ($this->rooms->tryCreate($code, $videoId)) {
                return Response::redirect('/room/' . $code, 303);
            }
        }

        throw new \RuntimeException('Unable to generate a unique room code after 5 attempts.');
    }

    public function show(string $code): Response
    {
        $room = $this->rooms->findByCode($code);
        if ($room === null) {
            return Response::html($this->view->render('pages/404', [
                'title' => 'Sala não encontrada',
                'heading' => 'Sala não encontrada',
                'message' => 'Não existe uma sala com o código informado.',
                'path' => '/room/' . $code,
            ]), 404);
        }

        return Response::html($this->view->render('pages/room', [
            'title' => 'Sala ' . $room['code'] . ' — Semyra',
            'room' => $room,
        ]));
    }
}
