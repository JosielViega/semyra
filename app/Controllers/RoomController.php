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
use App\Repositories\UserRepository;
use App\Repositories\UserRoomRepository;
use App\Services\AuthSession;
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
        private readonly UserRepository $users,
        private readonly AuthSession $auth,
        private readonly UserRoomRepository $userRooms,
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

        $creatorUserId = $this->authenticatedUser()['id'] ?? null;
        $this->rooms->deleteExpiredTemporaryRooms();

        for ($attempt = 0; $attempt < self::MAX_CODE_ATTEMPTS; ++$attempt) {
            $code = $this->codeGenerator->generate();
            if ($this->rooms->tryCreate($code, $creatorUserId)) {
                return Response::redirect('/room/' . $code, 303);
            }
        }

        throw new \RuntimeException('Unable to generate a unique room code after 5 attempts.');
    }

    public function show(string $code): Response
    {
        $room = $this->rooms->findByCode($code);
        if ($room === null) {
            $this->rooms->deleteExpiredTemporaryRoomByCode($code);
            return Response::html($this->view->render('pages/404', [
                'title' => 'Sala não encontrada',
                'heading' => 'Sala não encontrada',
                'message' => 'Não existe uma sala com o código informado.',
                'path' => '/room/' . $code,
            ]), 404);
        }

        $identity = $this->participantSession->identityFor($room['code']);
        $currentUser = $this->authenticatedUser();
        $userId = $currentUser['id'] ?? null;
        if ($currentUser !== null) {
            $identityBelongsToUser = $identity !== null
                && ($identity['user_id'] === null || $identity['user_id'] === $userId);
            $knownRoom = (int) ($room['created_by_user_id'] ?? 0) === $userId
                || $this->userRooms->hasParticipation($userId, (int) $room['id']);
            if ($identityBelongsToUser || $knownRoom) {
                $identity = $this->participantSession->rememberAccount(
                    $room['code'],
                    $userId,
                    $currentUser['display_name'],
                );
                $participantKeyHash = hash('sha256', $identity['participant_key']);
                $this->participants->touch(
                    (int) $room['id'],
                    $participantKeyHash,
                    $identity['display_name'],
                    null,
                    $userId,
                );
                $this->transmissions->claimOwnerAccount((int) $room['id'], $participantKeyHash, $userId);
                $this->userRooms->recordParticipation($userId, (int) $room['id']);
            } elseif ($identity !== null) {
                $identity = null;
            }
        }
        $participants = [];
        $transmission = null;
        if ($identity !== null) {
            $participantKeyHash = hash('sha256', $identity['participant_key']);
            $participants = array_map(
                static fn (array $participant): array => [
                    'name' => $participant['display_name'],
                    'is_you' => ($participant['user_id'] ?? null) !== null
                        ? $userId !== null && (int) $participant['user_id'] === $userId
                        : hash_equals($participantKeyHash, $participant['participant_key_hash']),
                ],
                $this->participants->activeForRoom((int) $room['id']),
            );
            $transmission = $this->transmissionPresenter->present(
                $this->transmissions->findByRoom((int) $room['id']),
                $participantKeyHash,
                $userId,
            );
        }

        return Response::html($this->view->render('pages/room', [
            'title' => 'Sala ' . $room['code'] . ' — Semyra',
            'room' => $room,
            'identity' => $identity,
            'currentUser' => $currentUser,
            'participants' => $participants,
            'transmission' => $transmission,
            'debug' => $this->request->query('debug') === '1',
            'flashes' => $this->session->consumeFlash(),
            'csrfField' => $this->csrf->field(),
            'csrfToken' => $this->csrf->token(),
        ], 'layouts/room'));
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
}
