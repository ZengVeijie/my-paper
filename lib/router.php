<?php
/**
 * My Paper - 路由系统
 */

class Router {
    private array $routes = [];

    public function get(string $pattern, callable $handler): void {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable $handler): void {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    public function put(string $pattern, callable $handler): void {
        $this->routes[] = ['PUT', $pattern, $handler];
    }

    public function delete(string $pattern, callable $handler): void {
        $this->routes[] = ['DELETE', $pattern, $handler];
    }

    public function dispatch(string $method, string $uri): void {
        // Support PUT/DELETE via _method POST field
        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper($_POST['_method']);
        }

        $uri = parse_url($uri, PHP_URL_PATH);
        // Strip subdirectory prefix if configured
        if (defined('BASE_PATH') && BASE_PATH && strpos($uri, BASE_PATH) === 0) {
            $uri = substr($uri, strlen(BASE_PATH));
        }
        $uri = rtrim($uri, '/') ?: '/';

        foreach ($this->routes as [$route_method, $pattern, $handler]) {
            if ($route_method !== $method) continue;

            $regex = $this->patternToRegex($pattern);
            if (preg_match($regex, $uri, $matches)) {
                array_shift($matches);
                // Filter to positional values only (PHP 8 named param compat)
                $args = array_values(array_filter($matches, fn($k) => is_int($k), ARRAY_FILTER_USE_KEY));
                $handler(...$args);
                return;
            }
        }

        http_response_code(404);
        require __DIR__ . '/../themes/404.php';
    }

    private function patternToRegex(string $pattern): string {
        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
        return '#^' . $pattern . '$#';
    }
}
