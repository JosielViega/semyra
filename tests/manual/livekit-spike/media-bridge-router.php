<?php

declare(strict_types=1);

use App\Controllers\MediaBridgeController;
use App\Core\Response;
use App\Repositories\MediaBridgeJobRepository;
use App\Services\LiveKitIngressGateway;
use App\Services\LiveKitRoomContext;
use App\Services\MediaBridgeCoordinator;
use App\Services\MediaBridgeWorkerAuthenticator;

$root = dirname(__DIR__, 3);
$app = require $root . '/bootstrap/app.php';
if (!in_array($app['config']['environment'], ['local', 'testing'], true)) {
    Response::json(['error' => 'not_found'], 404)->send();
}

final class ManualMediaBridgeIngressGateway implements LiveKitIngressGateway
{
    public function findIngressIdsByRoomAndName(string $roomName, string $ingressName): array { return []; }
    /** @var array<string, array{name: string, room: string, publisher: string}> */
    private array $ingresses = [];

    public function createWhipIngress(string $name, string $roomName, string $publisherIdentity): array
    {
        $id = 'TEST_' . substr(hash('sha256', $name . $roomName . $publisherIdentity), 0, 32);
        $this->ingresses[$id] = ['name' => $name, 'room' => $roomName, 'publisher' => $publisherIdentity];
        return [
            'ingress_id' => $id,
            'whip_endpoint' => 'https://whip.invalid/test-only-credential',
        ];
    }

    public function findOwnedIngressIds(string $roomName, string $ingressName, string $publisherIdentity): array
    {
        return array_keys(array_filter(
            $this->ingresses,
            static fn (array $ingress): bool => $ingress['room'] === $roomName
                && $ingress['name'] === $ingressName
                && $ingress['publisher'] === $publisherIdentity,
        ));
    }

    public function deleteIngress(string $ingressId): void
    {
        unset($this->ingresses[$ingressId]);
    }
}

$controller = new MediaBridgeController(
    $app['request'],
    new MediaBridgeWorkerAuthenticator($app['media_bridge'], $app['config']['environment']),
    new MediaBridgeCoordinator(
        new MediaBridgeJobRepository($app['database']),
        new ManualMediaBridgeIngressGateway(),
        new LiveKitRoomContext($app['livekit']['namespace']),
        $app['media_bridge']['lease_seconds'],
        $app['media_bridge']['max_failures'],
    ),
);
$router = $app['router'];
$router->post('/internal/media-bridge/claim', [$controller, 'claim']);
$router->post('/internal/media-bridge/heartbeat', [$controller, 'heartbeat']);
$router->post('/internal/media-bridge/report', [$controller, 'report']);
$router->fallback(static fn (): Response => Response::json(['error' => 'not_found'], 404));
$router->dispatch($app['request'])->send();
