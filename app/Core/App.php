<?php
declare(strict_types=1);

namespace App\Core;

final class App
{
    public static function run(): void
    {
        self::registerErrorHandling();
        self::assertProductionConfig();
        self::assertUploadFits();
        if (\App\Services\VisitorService::serveLive()) {
            return; // the live poll is answered without a session or routing
        }
        Session::start();
        self::securityHeaders();

        $router = new Router();
        (require BASE_PATH . '/config/routes.php')($router);
        $request = Request::capture();
        \App\Services\AffiliateService::captureFromQuery($request);
        \App\Services\VisitorService::track($request);
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

    /**
     * PHP silently empties $_POST and $_FILES when a request is larger than post_max_size, so the form would fail
     * later with a page the browser cannot read. Say what the limit is instead.
     */
    private static function assertUploadFits(): void
    {
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $length <= 0 || $_POST !== [] || $_FILES !== []) {
            return;
        }
        $limit = (string) ini_get('post_max_size');
        if ($length > self::iniBytes($limit)) {
            Response::abort(413, "That upload is too big for this server (the limit is {$limit}). Use smaller photos, or ask your host to raise post_max_size.");
        }
    }

    /** "8M", "2G", "512K" or a plain number of bytes, as PHP reads them from php.ini. */
    private static function iniBytes(string $value): int
    {
        $n = (int) $value;
        return match (strtoupper(substr(trim($value), -1))) {
            'G' => $n * 1024 * 1024 * 1024,
            'M' => $n * 1024 * 1024,
            'K' => $n * 1024,
            default => $n,
        };
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
