<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<string, list<array{path: string, pattern: string, handler: callable}>> */
    private array $routes = [];

    /** @var null|callable */
    private $notFoundHandler = null;

    public function get(string $path, callable|array $handler): self
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): self
    {
        return $this->add('POST', $path, $handler);
    }

    public function put(string $path, callable|array $handler): self
    {
        return $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, callable|array $handler): self
    {
        return $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, callable|array $handler): self
    {
        return $this->add('DELETE', $path, $handler);
    }

    public function add(string $method, string $path, callable|array $handler): self
    {
        $normalized = $this->normalizePath($path);
        $quoted = preg_quote($normalized, '#');
        $pattern = preg_replace('#\\\\\{([A-Za-z_][A-Za-z0-9_]*)\\\\\}#', '(?P<$1>[^/]+)', $quoted);

        $this->routes[strtoupper($method)][] = [
            'path' => $normalized,
            'pattern' => '#^' . $pattern . '$#u',
            'handler' => $handler,
        ];

        return $this;
    }

    public function fallback(callable $handler): self
    {
        $this->notFoundHandler = $handler;

        return $this;
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes[$request->method()] ?? [] as $route) {
            if (preg_match($route['pattern'], $request->path(), $matches) !== 1) {
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $response = ($route['handler'])(...array_values($params));

            if (!$response instanceof Response) {
                throw new \RuntimeException('Route handlers must return a Response.');
            }

            return $response;
        }

        if ($this->notFoundHandler !== null) {
            return ($this->notFoundHandler)($request);
        }

        return Response::html('Not Found', 404);
    }

    private function normalizePath(string $path): string
    {
        $normalized = '/' . trim($path, '/');

        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }
}
