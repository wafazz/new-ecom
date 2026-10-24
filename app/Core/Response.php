<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function abort(int $code, ?string $message = null): never
    {
        if (Request::wantsJson()) {
            self::json(['ok' => false, 'message' => $message ?? match ($code) {
                403 => 'You do not have permission to do that.', 404 => 'Not found.', 419 => 'Your session expired. Reload the page and try again.', default => 'Something went wrong.',
            }], $code);
        }
        http_response_code($code);
        $view = in_array($code, [403, 404, 405, 419], true) ? "errors/{$code}" : 'errors/500';
        echo View::render($view, ['message' => $message], null);
        exit;
    }

    public static function json(mixed $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
