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
use App\Services\RoomPlaybackTelemetry;
use App\Services\RoomParticipantSession;
use App\Services\RoomTransmissionPresenter;
use App\Validation\Validator;

final class RoomParticipantController
{
    public function __construct(
        private readonly Request $request,
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Validator $validator,
        private readonly RoomRepository $rooms,
        private readonly RoomParticipantRepository $participants,
        private readonly RoomTransmissionRepository $transmissions,
        private readonly RoomParticipantSession $participantSession,
        private readonly RoomPlaybackTelemetry $playbackTelemetry,
        private readonly RoomTransmissionPresenter $transmissionPresenter,
    ) {
    }

    public function join(string $code): Response
    {
        if (!$this->csrf->verify($this->request->input('_token'))) {
            return $this->invalidCsrfResponse();
        }

        $room = $this->rooms->findByCode($code);
        if ($room === null) {
            return $this->roomNotFoundResponse($code);
        }

        $submittedName = $this->request->input('display_name');
        $displayName = is_string($submittedName) ? trim($submittedName) : $submittedName;
        if (!$this->validator->validate(
            ['display_name' => $displayName],
            ['display_name' => 'required|string|max:30'],
        )) {
            $this->session->flash('error', 'Informe um apelido com até 30 caracteres.');
            return Response::redirect('/room/' . $room['code'], 303);
        }

        $identity = $this->participantSession->remember($room['code'], $displayName);
        $this->participants->touch(
            (int) $room['id'],
            hash('sha256', $identity['participant_key']),
            $identity['display_name'],
        );

        return Response::redirect('/room/' . $room['code'], 303);
    }

    public function presence(string $code): Response
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

        $participantKeyHash = hash('sha256', $identity['participant_key']);
        try {
            $playback = $this->playbackTelemetry->normalizePayload([
                'player_state' => $this->request->input('player_state'),
                'player_position_ms' => $this->request->input('player_position_ms'),
                'player_duration_ms' => $this->request->input('player_duration_ms'),
            ]);
        } catch (\InvalidArgumentException) {
            return Response::json(['error' => 'invalid_telemetry'], 422);
        }

        $this->participants->touch(
            (int) $room['id'],
            $participantKeyHash,
            $identity['display_name'],
            $playback,
        );

        return Response::json([
            'participants' => $this->playbackTelemetry->presentParticipants(
                $this->participants->activeForRoom((int) $room['id']),
                $participantKeyHash,
            ),
            'transmission' => $this->transmissionPresenter->present(
                $this->transmissions->findByRoom((int) $room['id']),
                $participantKeyHash,
            ),
        ]);
    }

    private function invalidCsrfResponse(): Response
    {
        return Response::html($this->view->render('pages/error', [
            'title' => 'Solicitação inválida',
            'heading' => 'Formulário inválido ou expirado',
            'message' => 'Recarregue a página e tente novamente.',
        ]), 419);
    }

    private function roomNotFoundResponse(string $code): Response
    {
        return Response::html($this->view->render('pages/404', [
            'title' => 'Sala não encontrada',
            'heading' => 'Sala não encontrada',
            'message' => 'Não existe uma sala com o código informado.',
            'path' => '/room/' . $code,
        ]), 404);
    }

}
