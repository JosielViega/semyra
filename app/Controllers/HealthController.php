<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;

final class HealthController
{
    public function index(): Response
    {
        return Response::json(['status' => 'ok']);
    }
}
