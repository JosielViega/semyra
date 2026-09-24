<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\View;

final class HomeController
{
    public function __construct(
        private readonly View $view,
        private readonly array $appConfig,
    ) {
    }

    public function index(): Response
    {
        return Response::html($this->view->render('pages/home', [
            'title' => 'Semyra — Assista junto.',
            'appName' => $this->appConfig['name'],
        ]));
    }
}
