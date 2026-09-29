<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\RoomRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserRoomRepository;
use App\Services\AuthSession;

final class MyRoomsController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly RoomRepository $rooms,
        private readonly UserRoomRepository $userRooms,
        private readonly UserRepository $users,
        private readonly AuthSession $auth,
    ) {
    }

    public function index(): Response
    {
        $userId = $this->auth->userId();
        if ($userId === null) {
            return Response::redirect('/login', 303);
        }

        $currentUser = $this->users->findById($userId);
        if ($currentUser === null) {
            $this->auth->logout();
            return Response::redirect('/login', 303);
        }

        $this->rooms->deleteExpiredTemporaryRooms();

        return Response::html($this->view->render('pages/my-rooms', [
            'title' => 'Minhas salas — Semyra',
            'csrfField' => $this->csrf->field(),
            'flashes' => $this->session->consumeFlash(),
            'currentUser' => $currentUser,
            'createdRooms' => $this->rooms->createdByUser($userId),
            'participatedRooms' => $this->userRooms->participatedByUser($userId),
        ]));
    }
}
