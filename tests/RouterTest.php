<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testDispatchesGetRouteWithParameter(): void
    {
        $router = new Router();
        $router->get('/users/{id}', static fn (string $id): Response => Response::html($id));

        $response = $router->dispatch(new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/users/42?expanded=1',
        ]));

        self::assertSame(200, $response->status());
        self::assertSame('42', $response->body());
    }

    public function testUsesFallbackWith404Status(): void
    {
        $router = new Router();
        $router->fallback(static fn (): Response => Response::html('missing', 404));

        $response = $router->dispatch(new Request(server: [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/missing',
        ]));

        self::assertSame(404, $response->status());
    }

    public function testSupportsMethodOverrideForPreparedMethods(): void
    {
        $router = new Router();
        $router->delete('/items/{id}', static fn (string $id): Response => Response::html($id));
        $request = new Request(parsedBody: ['_method' => 'DELETE'], server: [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/items/7',
        ]);

        self::assertSame('7', $router->dispatch($request)->body());
    }
}
