<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Minimal hand-rolled router. No framework lock-in (see ARCHITECTURE.md
 * section 2 — avoiding Laravel/Symfony assumptions that don't fit shared
 * hosting cleanly). A route is: method, path (exact match or {param}
 * segments), and a callable. Middleware is just callables run in order
 * before the handler; any of them may call Router::abort() to short-circuit.
 */
final class Router
{
    /** @var array<int, array{method:string, pattern:string, paramNames:array<int,string>, regex:string, handler:callable, middleware:array<int,callable>}> */
    private array $routes = [];

    public function get(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    private function add(string $method, string $path, callable $handler, array $middleware): void
    {
        $paramNames = [];
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '([^/]+)';
        }, $path);
        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $path,
            'paramNames' => $paramNames,
            'regex'      => $regex,
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['regex'], $path, $matches)) {
                array_shift($matches);
                $params = array_combine($route['paramNames'], $matches) ?: [];

                foreach ($route['middleware'] as $mw) {
                    $result = $mw($params);
                    if ($result === false) {
                        return; // middleware already sent a response (redirect/403/etc.)
                    }
                }

                ($route['handler'])($params);
                return;
            }
        }

        http_response_code(404);
        echo '404 Not Found';
    }
}
