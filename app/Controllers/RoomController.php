<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\RoomParticipantRepository;
use App\Repositories\RoomRepository;
use App\Repositories\RoomTransmissionRepository;
use App\Services\RoomCodeGenerator;
use App\Services\RoomParticipantSession;
use App\Services\RoomTransmissionPresenter;

final class RoomController
{
    private const MAX_CODE_ATTEMPTS = 5;

    public function __construct(
        private readonly Request $request,
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly RoomRepository $rooms,
        private readonly RoomParticipantRepository $participants,
        private readonly RoomTransmissionRepository $transmissions,
        private readonly RoomParticipantSession $participantSession,
        private readonly RoomTransmissionPresenter $transmissionPresenter,
        private readonly RoomCodeGenerator $codeGenerator,
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

        for ($attempt = 0; $attempt < self::MAX_CODE_ATTEMPTS; ++$attempt) {
            $code = $this->codeGenerator->generate();
            if ($this->rooms->tryCreate($code)) {
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

        $identity = $this->participantSession->identityFor($room['code']);
        $participants = [];
        $transmission = null;
        if ($identity !== null) {
            $participantKeyHash = hash('sha256', $identity['participant_key']);
            $participants = array_map(
                static fn (array $participant): array => [
                    'name' => $participant['display_name'],
                    'is_you' => hash_equals($participantKeyHash, $participant['participant_key_hash']),
                ],
                $this->participants->activeForRoom((int) $room['id']),
            );
            $transmission = $this->transmissionPresenter->present(
                $this->transmissions->findByRoom((int) $room['id']),
                $participantKeyHash,
            );
        }

        return Response::html($this->view->render('pages/room', [
            'title' => 'Sala ' . $room['code'] . ' — Semyra',
            'room' => $room,
            'identity' => $identity,
            'participants' => $participants,
            'transmission' => $transmission,
            'debug' => $this->request->query('debug') === '1',
            'flashes' => $this->session->consumeFlash(),
            'csrfField' => $this->csrf->field(),
            'csrfToken' => $this->csrf->token(),
        ], 'layouts/room'));
    }
}
