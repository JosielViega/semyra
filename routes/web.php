<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\RoomController;
use App\Controllers\RoomParticipantController;
use App\Controllers\RoomTransmissionController;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\RoomParticipantRepository;
use App\Repositories\RoomRepository;
use App\Repositories\RoomTransmissionRepository;
use App\Repositories\UserRepository;
use App\Services\AuthSession;
use App\Services\RoomCodeGenerator;
use App\Services\RoomParticipantSession;
use App\Services\RoomPlaybackTelemetry;
use App\Services\RoomTransmissionPresenter;
use App\Services\RoomTransmissionPlayback;
use App\Services\YouTubeUrlParser;

$userRepository = new UserRepository($app['database']);
$authSession = new AuthSession($app['session']);
$home = new HomeController(
    $app['view'],
    $app['session'],
    $app['csrf'],
    $app['config'],
    $userRepository,
    $authSession,
);
$auth = new AuthController(
    $app['request'],
    $app['view'],
    $app['session'],
    $app['csrf'],
    $app['validator'],
    $userRepository,
    $authSession,
);
$roomRepository = new RoomRepository($app['database']);
$participantRepository = new RoomParticipantRepository($app['database']);
$transmissionRepository = new RoomTransmissionRepository($app['database']);
$participantSession = new RoomParticipantSession($app['session']);
$playbackTelemetry = new RoomPlaybackTelemetry();
$transmissionPresenter = new RoomTransmissionPresenter();
$transmissionPlayback = new RoomTransmissionPlayback();
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
    $transmissionPlayback,
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
    $transmissionPlayback,
    $transmissionPresenter,
);
$health = new HealthController();
$router = $app['router'];

$router->get('/', [$home, 'index']);
$router->get('/register', [$auth, 'showRegister']);
$router->post('/register', [$auth, 'register']);
$router->get('/login', [$auth, 'showLogin']);
$router->post('/login', [$auth, 'login']);
$router->post('/logout', [$auth, 'logout']);
$router->post('/rooms', [$rooms, 'store']);
$router->get('/room/{code}', [$rooms, 'show']);
$router->post('/room/{code}/join', [$roomParticipants, 'join']);
$router->post('/room/{code}/presence', [$roomParticipants, 'presence']);
$router->post('/room/{code}/leave', [$roomParticipants, 'leave']);
$router->post('/room/{code}/transmission', [$roomTransmissions, 'start']);
$router->post('/room/{code}/transmission/end', [$roomTransmissions, 'end']);
$router->post('/room/{code}/transmission/playback', [$roomTransmissions, 'playback']);
$router->get('/health', [$health, 'index']);
$router->fallback(static function (Request $request) use ($app): Response {
    return Response::html($app['view']->render('pages/404', [
        'title' => 'Página não encontrada',
        'path' => $request->path(),
    ]), 404);
});

return $router;
