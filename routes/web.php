<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\RoomController;
use App\Controllers\RoomParticipantController;
use App\Controllers\RoomTransmissionController;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\RoomParticipantRepository;
use App\Repositories\RoomRepository;
use App\Repositories\RoomTransmissionRepository;
use App\Services\RoomCodeGenerator;
use App\Services\RoomParticipantSession;
use App\Services\RoomPlaybackTelemetry;
use App\Services\RoomTransmissionPresenter;
use App\Services\YouTubeUrlParser;

$home = new HomeController(
    $app['view'],
    $app['session'],
    $app['csrf'],
    $app['config'],
);
$roomRepository = new RoomRepository($app['database']);
$participantRepository = new RoomParticipantRepository($app['database']);
$transmissionRepository = new RoomTransmissionRepository($app['database']);
$participantSession = new RoomParticipantSession($app['session']);
$playbackTelemetry = new RoomPlaybackTelemetry();
$transmissionPresenter = new RoomTransmissionPresenter();
$rooms = new RoomController(
    $app['request'],
    $app['view'],
    $app['session'],
    $app['csrf'],
    $roomRepository,
    $participantRepository,
    $transmissionRepository,
    $participantSession,
    $transmissionPresenter,
    new RoomCodeGenerator(),
);
$roomParticipants = new RoomParticipantController(
    $app['request'],
    $app['view'],
    $app['session'],
    $app['csrf'],
    $app['validator'],
    $roomRepository,
    $participantRepository,
    $transmissionRepository,
    $participantSession,
    $playbackTelemetry,
    $transmissionPresenter,
);
$roomTransmissions = new RoomTransmissionController(
    $app['request'],
    $app['view'],
    $app['session'],
    $app['csrf'],
    $roomRepository,
    $transmissionRepository,
    $participantSession,
    new YouTubeUrlParser(),
);
$health = new HealthController();
$router = $app['router'];

$router->get('/', [$home, 'index']);
$router->post('/rooms', [$rooms, 'store']);
$router->get('/room/{code}', [$rooms, 'show']);
$router->post('/room/{code}/join', [$roomParticipants, 'join']);
$router->post('/room/{code}/presence', [$roomParticipants, 'presence']);
$router->post('/room/{code}/transmission', [$roomTransmissions, 'start']);
$router->post('/room/{code}/transmission/end', [$roomTransmissions, 'end']);
$router->get('/health', [$health, 'index']);
$router->fallback(static function (Request $request) use ($app): Response {
    return Response::html($app['view']->render('pages/404', [
        'title' => 'Página não encontrada',
        'path' => $request->path(),
    ]), 404);
});

return $router;
