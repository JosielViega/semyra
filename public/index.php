<?php

declare(strict_types=1);

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$router = require dirname(__DIR__) . '/routes/web.php';

$app['remember_me']->restore();
$app['remember_me']->applyTo($router->dispatch($app['request']))->send();
