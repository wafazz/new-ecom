<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];
    private array $groupStack = [];

    /** @param string[] $middleware */
    public function group(string $prefix, array $middleware, callable $fn): void
    {
        $this->groupStack[] = ['prefix' => $prefix, 'middleware' => $middleware];
        $fn($this);
        array_pop($this->groupStack);
    }

    public function get(string $path, string $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, string $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    private function add(string $method, string $path, string $handler, array $middleware): void
    {
        $prefix = '';
        foreach ($this->groupStack as $g) {
            $prefix .= $g['prefix'];
            $middleware = [...$g['middleware'], ...$middleware];
        }
        $full = '/' . trim($prefix . '/' . trim($path, '/'), '/');
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $full) . '$#';
        $this->routes[] = compact('method', 'regex', 'handler', 'middleware');
    }

    public function dispatch(Request $request): void
    {
        // Public URLs never carry a .php extension (index.php is internal only).
        if (preg_match('#\.php(/|$)#i', $request->path)) {
            Response::abort(404);
        }

        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

            foreach ($route['middleware'] as $mw) {
                [$name, $arg] = array_pad(explode(':', $mw, 2), 2, null);
                $class = 'App\\Middleware\\' . ucfirst($name) . 'Middleware';
                (new $class())->handle($request, $arg);
            }

            [$controller, $action] = explode('@', $route['handler']);
            $class = 'App\\Controllers\\' . str_replace('/', '\\', $controller);
            (new $class())->$action($request, ...array_values($params));
            return;
        }

        Response::abort($pathMatched ? 405 : 404);
    }
}
