<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Services\AuthSession;

final class HomeController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly array $appConfig,
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

        return Response::html($this->view->render('pages/home', [
            'title' => 'Semyra — Assista junto.',
            'appName' => $this->appConfig['name'],
            'csrfField' => $this->csrf->field(),
            'flashes' => $this->session->consumeFlash(),
            'currentUser' => $currentUser,
        ]));
    }
}
