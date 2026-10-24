<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
    ) {}

    public static function capture(): self
    {
        $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

        // Support running under a sub-folder (APP_URL path).
        $base = rtrim((string) parse_url((string) env('APP_URL', ''), PHP_URL_PATH), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        return new self($method, '/' . trim($path, '/'), $_GET, $_POST);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    /** Input as a trimmed-agnostic string; arrays/objects (e.g. ?q[]=x) become the default instead of erroring. */
    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key);
        return is_scalar($v) ? (string) $v : $default;
    }

    /** fetch()/XHR callers (our own JS) ask for JSON instead of redirects and HTML error pages. */
    public static function wantsJson(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
