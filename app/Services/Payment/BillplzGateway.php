<?php
declare(strict_types=1);

namespace App\Services\Payment;

use App\Core\Database;
use App\Services\BillplzSettings;
use App\Services\PricingService;

/**
 * Billplz API calls and signature checks (https://support.billplz.com/api).
 * Callback and redirect data are only trusted after the X-Signature HMAC-SHA256 verifies; amounts are
 * compared with OUR order, never taken from the request.
 */
final class BillplzGateway
{
    // ---- X-Signature ------------------------------------------------------------------------

    /** HMAC-SHA256 over the "key+value" strings, sorted case-insensitively and joined with "|". */
    public static function sign(array $pairs, string $key): string
    {
        $src = [];
        foreach ($pairs as $k => $v) {
            $src[] = $k . $v;
        }
        usort($src, 'strcasecmp');
        return hash_hmac('sha256', implode('|', $src), $key);
    }

    /** Callback (POST) body: every field except x_signature. Non-scalar values make the request invalid. */
    public static function callbackValid(array $post, string $key): bool
    {
        $given = $post['x_signature'] ?? null;
        if (!is_string($given)) {
            return false;
        }
        $pairs = [];
        foreach ($post as $k => $v) {
            if ($k === 'x_signature') {
                continue;
            }
            if (!is_string($k) || !is_scalar($v)) {
                return false;
            }
            $pairs[$k] = (string) $v;
        }
        return hash_equals(self::sign($pairs, $key), $given);
    }

    /** Redirect (GET): the billplz[...] array, each pair prefixed with "billplz". */
    public static function redirectValid(mixed $billplz, string $key): bool
    {
        if (!is_array($billplz) || !is_string($billplz['x_signature'] ?? null)) {
            return false;
        }
        $pairs = [];
        foreach ($billplz as $k => $v) {
            if ($k === 'x_signature') {
                continue;
            }
            if (!is_string($k) || !is_scalar($v)) {
                return false;
            }
            $pairs['billplz' . $k] = (string) $v;
        }
        return hash_equals(self::sign($pairs, $key), $billplz['x_signature']);
    }

    // ---- API ------------------------------------------------------------------------------

    /** +60... for Malaysian numbers; null when it doesn't look like a number (email alone is enough for Billplz). */
    public static function mobile(string $phone): ?string
    {
        $m = preg_replace('/[\s\-]/', '', $phone);
        $m = match (true) {
            str_starts_with($m, '+') => $m,
            str_starts_with($m, '0') => '+60' . substr($m, 1),
            str_starts_with($m, '60') => '+' . $m,
            default => '+60' . $m,
        };
        return preg_match('/^\+\d{8,15}$/', $m) ? $m : null;
    }

    /** Only ever send customers to Billplz's own pages. */
    public static function safeUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $override = strtolower((string) parse_url((string) env('BILLPLZ_BASE_URL', ''), PHP_URL_HOST));
        $https = parse_url($url, PHP_URL_SCHEME) === 'https';
        return ($https && in_array($host, ['www.billplz.com', 'www.billplz-sandbox.com'], true)) || ($override !== '' && $host === $override);
    }

    /** Origins the CSP must allow as form-action (Chrome applies it to the redirect after the checkout POST). */
    public static function formActionHosts(): array
    {
        $hosts = ['https://www.billplz.com', 'https://www.billplz-sandbox.com'];
        $o = (string) env('BILLPLZ_BASE_URL', '');
        if ($o !== '' && ($p = parse_url($o)) && isset($p['scheme'], $p['host'])) {
            $hosts[] = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        }
        return $hosts;
    }

    /** @return array{code:int, json:?array} code 0 = network failure */
    private static function call(string $method, string $path, array $creds, ?array $fields = null): array
    {
        $ch = curl_init($creds['base_url'] . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERPWD => $creds['api_key'] . ':',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (env('BILLPLZ_BASE_URL') ? CURLPROTO_HTTP : 0),
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($fields ?? []);
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = $body === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = is_string($body) ? json_decode($body, true) : null;
        return ['code' => $code, 'json' => is_array($json) ? $json : null];
    }

    /** @return array{id:string,url:string} @throws \RuntimeException with a customer-safe message */
    public static function createBill(array $order, array $creds): array
    {
        $fields = [
            'collection_id' => $creds['collection_id'],
            'email' => $order['customer_email'],
            'name' => mb_substr($order['customer_name'], 0, 255),
            'amount' => PricingService::cents((string) $order['total']),
            'callback_url' => url('/payments/billplz/callback'),
            'redirect_url' => url('/payments/billplz/return'),
            'description' => 'Order ' . $order['order_no'],
            'reference_1_label' => 'Order',
            'reference_1' => $order['order_no'],
            'deliver' => 'false',
        ];
        if (($mobile = self::mobile($order['customer_phone'])) !== null) {
            $fields['mobile'] = $mobile;
        }
        $r = self::call('POST', '/v3/bills', $creds, $fields);
        $id = $r['json']['id'] ?? null;
        $url = $r['json']['url'] ?? null;
        if (!in_array($r['code'], [200, 201], true) || !is_string($id) || !is_string($url) || !preg_match('/^[A-Za-z0-9_\-]{4,40}$/', $id) || !self::safeUrl($url)) {
            self::log("create bill failed for {$order['order_no']}: HTTP {$r['code']}");
            throw new \RuntimeException($r['code'] === 0 ? 'Could not reach Billplz. Please try again.' : 'Billplz could not create the payment. Please try again or choose another method.');
        }
        return ['id' => $id, 'url' => $url];
    }

    /** Authoritative bill state straight from Billplz (null on any failure). */
    public static function fetchBill(string $billId, array $creds): ?array
    {
        $r = self::call('GET', '/v3/bills/' . rawurlencode($billId), $creds);
        return $r['code'] === 200 ? $r['json'] : null;
    }

    /**
     * Create (or reuse) the bill for an order. Row-locked so double clicks can't create two bills.
     * @return array{url?:string, error?:string}
     */
    public static function start(int $orderId): array
    {
        $creds = BillplzSettings::credentials();
        if ($creds === null) {
            return ['error' => 'Online payment is not available right now.'];
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $o = Database::query('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId])->fetch();
            if (!$o || $o['payment_method'] !== 'billplz' || $o['status'] !== 'pending' || $o['payment_status'] !== 'unpaid') {
                $pdo->rollBack();
                return ['error' => "This order can't be paid online."];
            }
            if ($o['billplz_url'] !== null && self::safeUrl((string) $o['billplz_url'])) {
                $pdo->commit();
                return ['url' => (string) $o['billplz_url']];
            }
            $bill = self::createBill($o, $creds);
            Database::query('UPDATE orders SET billplz_bill_id = ?, billplz_url = ? WHERE id = ?', [$bill['id'], $bill['url'], $orderId]);
            Database::query('INSERT INTO order_events (order_id, event, note, actor_id) VALUES (?, ?, ?, NULL)', [$orderId, 'payment_started', 'Billplz bill ' . $bill['id']]);
            $pdo->commit();
            return ['url' => $bill['url']];
        } catch (\RuntimeException $e) {
            $pdo->rollBack();
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function orderByBill(string $billId): ?array
    {
        return Database::query('SELECT * FROM orders WHERE billplz_bill_id = ?', [$billId])->fetch() ?: null;
    }

    public static function log(string $msg): void
    {
        @file_put_contents(BASE_PATH . '/storage/logs/app.log', '[' . date('c') . '] billplz: ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    }
}
