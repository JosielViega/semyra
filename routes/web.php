<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\RoomController;
use App\Controllers\RoomParticipantController;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\RoomParticipantRepository;
use App\Repositories\RoomRepository;
use App\Services\RoomCodeGenerator;
use App\Services\RoomParticipantSession;
use App\Services\YouTubeUrlParser;

$home = new HomeController(
    $app['view'],
    $app['session'],
    $app['csrf'],
    $app['config'],
);
$roomRepository = new RoomRepository($app['database']);
$participantRepository = new RoomParticipantRepository($app['database']);
$participantSession = new RoomParticipantSession($app['session']);
$rooms = new RoomController(
    $app['request'],
    $app['view'],
    $app['session'],
    $app['csrf'],
    $roomRepository,
    $participantRepository,
    $participantSession,
    new RoomCodeGenerator(),
    new YouTubeUrlParser(),
);
$roomParticipants = new RoomParticipantController(
    $app['request'],
    $app['view'],
    $app['session'],
    $app['csrf'],
    $app['validator'],
    $roomRepository,
    $participantRepository,
    $participantSession,
);
$health = new HealthController();
$router = $app['router'];

$router->get('/', [$home, 'index']);
$router->post('/rooms', [$rooms, 'store']);
$router->get('/room/{code}', [$rooms, 'show']);
$router->post('/room/{code}/join', [$roomParticipants, 'join']);
$router->post('/room/{code}/presence', [$roomParticipants, 'presence']);
$router->get('/health', [$health, 'index']);
$router->fallback(static function (Request $request) use ($app): Response {
    return Response::html($app['view']->render('pages/404', [
        'title' => 'Página não encontrada',
        'path' => $request->path(),
    ]), 404);
});

return $router;
