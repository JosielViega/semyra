<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Core\Request;
use App\Core\Response;

$home = new HomeController(
    $app['view'],
    $app['config'],
);
$health = new HealthController();
$router = $app['router'];

$router->get('/', [$home, 'index']);
$router->get('/health', [$health, 'index']);
$router->fallback(static function (Request $request) use ($app): Response {
    return Response::html($app['view']->render('pages/404', [
        'title' => 'Page not found',
        'path' => $request->path(),
    ]), 404);
});

return $router;
