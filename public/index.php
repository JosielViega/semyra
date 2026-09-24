<?php

declare(strict_types=1);

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$router = require dirname(__DIR__) . '/routes/web.php';

$router->dispatch($app['request'])->send();
