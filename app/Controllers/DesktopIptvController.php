<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Response;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Services\AuthSession;

final class DesktopIptvController
{
    public function __construct(
        private readonly View $view,
        private readonly Csrf $csrf,
        private readonly UserRepository $users,
        private readonly AuthSession $auth,
    ) {
    }

    public function index(): Response
    {
        $currentUser = null;
        $userId = $this->auth->userId();
        if ($userId !== null) {
            $currentUser = $this->users->findById($userId);
            if ($currentUser === null) {
                $this->auth->logout();
            }
        }

        if ($currentUser === null) {
            return Response::redirect('/login', 303);
        }

        return Response::html($this->view->render('pages/desktop-iptv', [
            'title' => 'Minha IPTV — Semyra',
            'csrfField' => $this->csrf->field(),
            'currentUser' => $currentUser,
        ]));
    }
}
