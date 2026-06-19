<?php
declare(strict_types=1);

namespace MadMen\Core;

final class Router
{
    /** @var array<int, array{0:string,1:string,2:mixed}> */
    private array $routes = [];

    public function get(string $pattern, mixed $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, mixed $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, mixed $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    private function add(string $method, string $pattern, mixed $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    public function dispatch(string $method, string $uri): void
    {
        foreach ($this->routes as [$routeMethod, $regex, $handler]) {
            if ($routeMethod !== $method) {
                continue;
            }
            if (preg_match($regex, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $callable = is_array($handler) ? [new $handler[0](), $handler[1]] : $handler;
                $callable($params);
                return;
            }
        }

        Response::json(['error' => 'Not Found', 'path' => $uri], 404);
    }
}
