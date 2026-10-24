<?php
declare(strict_types=1);

namespace App\Core;

final class App
{
    public static function run(): void
    {
        self::registerErrorHandling();
        self::assertProductionConfig();
        Session::start();
        self::securityHeaders();

        $router = new Router();
        (require BASE_PATH . '/config/routes.php')($router);
        $request = Request::capture();
        \App\Services\AffiliateService::captureFromQuery($request);
        $router->dispatch($request);
    }

    /** A production site must not run with the placeholder key (it salts hashed IPs) or with debug output on. */
    private static function assertProductionConfig(): void
    {
        if (env('APP_ENV') !== 'production') {
            return;
        }
        $key = (string) env('APP_KEY', '');
        if (strlen($key) < 32 || str_starts_with($key, 'change-me')) {
            throw new \RuntimeException('APP_KEY must be set to a random string of at least 32 characters in production.');
        }
        if (env('APP_DEBUG')) {
            throw new \RuntimeException('APP_DEBUG must be false in production.');
        }
    }

    private static function securityHeaders(): void
    {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        // Views still use a few inline scripts/handlers, so script-src keeps 'unsafe-inline'; everything else is locked to this origin.
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'self'; form-action 'self' " . implode(' ', \App\Services\Payment\BillplzGateway::formActionHosts()) . "; frame-ancestors 'self'");
        if (str_starts_with((string) env('APP_URL', ''), 'https')) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    private static function registerErrorHandling(): void
    {
        $debug = (bool) env('APP_DEBUG', false);
        ini_set('display_errors', '0');
        error_reporting(E_ALL);

        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            throw new \ErrorException($str, 0, $no, $file, $line);
        });

        set_exception_handler(static function (\Throwable $e) use ($debug): void {
            $entry = sprintf("[%s] %s: %s in %s:%d\n%s\n", date('c'), get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
            @file_put_contents(BASE_PATH . '/storage/logs/app.log', $entry, FILE_APPEND | LOCK_EX);

            http_response_code(500);
            if ($debug) {
                echo '<pre style="padding:1rem;white-space:pre-wrap">' . e($entry) . '</pre>';
                return;
            }
            echo View::render('errors/500', [], null);
        });
    }
}
