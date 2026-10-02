<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\MyRoomsController;
use App\Controllers\RoomController;
use App\Controllers\RoomParticipantController;
use App\Controllers\RoomLiveKitController;
use App\Controllers\RoomTransmissionController;
use App\Controllers\MediaBridgeController;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\RoomParticipantRepository;
use App\Repositories\RoomRepository;
use App\Repositories\RoomTransmissionRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserRoomRepository;
use App\Repositories\MediaBridgeJobRepository;
use App\Services\AuthSession;
use App\Services\RoomCodeGenerator;
use App\Services\LiveKitRoomContext;
use App\Services\LiveKitViewerTokenService;
use App\Services\RoomParticipantSession;
use App\Services\RoomPlaybackTelemetry;
use App\Services\RoomTransmissionPresenter;
use App\Services\RoomTransmissionPlayback;
use App\Services\YouTubeUrlParser;
use App\Services\MediaBridgeCoordinator;
use App\Services\MediaBridgeWorkerAuthenticator;
use App\Services\SdkLiveKitIngressGateway;

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
$userRoomRepository = new UserRoomRepository($app['database']);
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
    $userRepository,
    $authSession,
    $userRoomRepository,
);
$myRooms = new MyRoomsController(
    $app['view'],
    $app['session'],
    $app['csrf'],
    $roomRepository,
    $userRoomRepository,
    $userRepository,
    $authSession,
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
    $userRepository,
    $userRoomRepository,
    $authSession,
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
    $userRepository,
    $authSession,
);
$roomLiveKit = new RoomLiveKitController(
    $app['request'],
    $app['csrf'],
    $roomRepository,
    $transmissionRepository,
    $participantSession,
    $userRepository,
    $authSession,
    new LiveKitRoomContext($app['livekit']['namespace']),
    new LiveKitViewerTokenService($app['livekit']),
);
$mediaBridgeJobs = new MediaBridgeJobRepository($app['database']);
$mediaBridge = new MediaBridgeController(
    $app['request'],
    new MediaBridgeWorkerAuthenticator($app['media_bridge'], $app['config']['environment']),
    new MediaBridgeCoordinator(
        $mediaBridgeJobs,
        new SdkLiveKitIngressGateway($app['livekit']),
        new LiveKitRoomContext($app['livekit']['namespace']),
        $app['media_bridge']['lease_seconds'],
        $app['media_bridge']['max_attempts'],
    ),
);
$health = new HealthController();
$router = $app['router'];

$router->get('/', [$home, 'index']);
$router->get('/register', [$auth, 'showRegister']);
$router->post('/register', [$auth, 'register']);
$router->get('/login', [$auth, 'showLogin']);
$router->post('/login', [$auth, 'login']);
$router->post('/logout', [$auth, 'logout']);
$router->get('/rooms', [$myRooms, 'index']);
$router->post('/rooms', [$rooms, 'store']);
$router->get('/room/{code}', [$rooms, 'show']);
$router->post('/room/{code}/join', [$roomParticipants, 'join']);
$router->post('/room/{code}/presence', [$roomParticipants, 'presence']);
$router->post('/room/{code}/leave', [$roomParticipants, 'leave']);
$router->post('/room/{code}/transmission', [$roomTransmissions, 'start']);
$router->post('/room/{code}/transmission/end', [$roomTransmissions, 'end']);
$router->post('/room/{code}/transmission/playback', [$roomTransmissions, 'playback']);
$router->post('/room/{code}/livekit/viewer-token', [$roomLiveKit, 'viewerToken']);
$router->post('/internal/media-bridge/claim', [$mediaBridge, 'claim']);
$router->post('/internal/media-bridge/heartbeat', [$mediaBridge, 'heartbeat']);
$router->post('/internal/media-bridge/report', [$mediaBridge, 'report']);
$router->get('/health', [$health, 'index']);
$router->fallback(static function (Request $request) use ($app): Response {
    return Response::html($app['view']->render('pages/404', [
        'title' => 'Página não encontrada',
        'path' => $request->path(),
    ]), 404);
});

return $router;
