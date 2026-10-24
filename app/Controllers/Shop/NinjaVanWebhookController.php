<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Controller;
use App\Core\Request;
use App\Services\NinjaVanSettings;
use App\Services\Shipping\NinjaVanGateway as G;

/** NinjaVan -> us tracking events. Authenticated by X-Ninjavan-Hmac-SHA256 only (server-to-server: no session, no CSRF token). */
final class NinjaVanWebhookController extends Controller
{
    public function receive(Request $request): void
    {
        $creds = NinjaVanSettings::credentials();
        if ($creds === null) {
            http_response_code(503);
            exit('not configured');
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, 1_000_000);
        if (!G::webhookValid($raw, $_SERVER['HTTP_X_NINJAVAN_HMAC_SHA256'] ?? null, $creds['client_secret'])) {
            G::log('webhook rejected: bad signature from ' . $request->ip());
            http_response_code(403);
            exit('invalid signature');
        }
        $payload = json_decode($raw, true);
        // Valid signature: always answer 200 (NinjaVan retries otherwise), even for events we have no use for.
        $result = is_array($payload) ? G::recordEvent($payload) : 'ignored';
        header('Content-Type: text/plain; charset=utf-8');
        exit($result === 'unknown' ? 'OK (unknown tracking id)' : 'OK');
    }
}
