<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\Shipping\NinjaVanGateway;

/**
 * Sends WhatsApp messages through onsend.io (POST /api/v1/send, Bearer token per device).
 * A failed message never stops an order from changing: errors are logged and the order moves on.
 */
final class WhatsAppService
{
    public const BASE = 'https://onsend.io/api/v1';

    /** Tests replace the network call with a stub: fn(string $method, string $url, string $token, ?string $body): array. */
    private static ?\Closure $transport = null;

    public static function setTransport(?\Closure $transport): void
    {
        self::$transport = $transport;
    }

    /** Digits with country code (60123456789), or null when the number does not look like a Malaysian/international mobile. */
    public static function phone(string $raw): ?string
    {
        $e164 = NinjaVanGateway::phone($raw);
        return $e164 === null ? null : ltrim($e164, '+');
    }

    /** Fill the placeholders; unknown placeholders are left as they are. */
    public static function render(string $template, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{' . $k . '}'] = (string) $v;
        }
        return strtr($template, $map);
    }

    /** @return array{ok:bool, message:string, id:?int} */
    public static function send(string $phone, string $text): array
    {
        $token = WhatsAppSettings::token();
        if ($token === null) {
            return ['ok' => false, 'message' => 'WhatsApp token is not set.', 'id' => null];
        }
        $body = json_encode(['phone_number' => $phone, 'type' => 'text', 'message' => $text], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $r = self::http('POST', self::BASE . '/send', $token, $body);
        $json = is_array($r['json']) ? $r['json'] : [];
        if ($r['code'] === 200 && ($json['success'] ?? false) === true) {
            return ['ok' => true, 'message' => 'sent', 'id' => isset($json['message_id']) ? (int) $json['message_id'] : null];
        }
        $msg = is_string($json['message'] ?? null) ? $json['message'] : ($r['code'] === 0 ? 'Network problem' : "HTTP {$r['code']}");
        return ['ok' => false, 'message' => mb_substr($msg, 0, 255), 'id' => null];
    }

    /** Connection check for the settings page: GET /api/v1/status. @return array{ok:bool, message:string} */
    public static function check(): array
    {
        $token = WhatsAppSettings::token();
        if ($token === null) {
            return ['ok' => false, 'message' => 'Save the API token first.'];
        }
        $r = self::http('GET', self::BASE . '/status', $token, null);
        $json = is_array($r['json']) ? $r['json'] : [];
        if ($r['code'] !== 200) {
            return ['ok' => false, 'message' => $r['code'] === 0 ? 'Could not reach onsend.io.' : "onsend.io answered HTTP {$r['code']}."];
        }
        $status = (string) ($json['status'] ?? 'unknown');
        return ['ok' => $status === 'connected', 'message' => 'Device status: ' . $status . (isset($json['phone_number']) ? ' (' . $json['phone_number'] . ')' : '')];
    }

    /**
     * Called after an order changes status. Sends the status template to the customer if WhatsApp is on and
     * that status is switched on. Never throws, never blocks the order change for long.
     */
    public static function notifyStatus(int $orderId, string $status): void
    {
        try {
            if (!WhatsAppSettings::enabled() || !isset(WhatsAppSettings::STATUSES[$status])) {
                return;
            }
            $tpl = WhatsAppSettings::template($status);
            if (!$tpl['on'] || trim($tpl['text']) === '') {
                return;
            }
            $order = OrderService::find($orderId);
            if ($order === null) {
                return;
            }
            $ship = ShipmentService::active($orderId);
            $vars = [
                'name' => (string) $order['customer_name'],
                'order_no' => (string) $order['order_no'],
                'total' => number_format((float) $order['total'], 2, '.', ''),
                'status' => WhatsAppSettings::STATUSES[$status],
                'tracking' => (string) ($ship['tracking_number'] ?? ''),
                'shop' => (string) config('app.name'),
                'ordered_item' => self::orderedItems($orderId),
            ];
            $text = self::render($tpl['text'], $vars);
            $phone = self::phone((string) $order['customer_phone']);
            if ($phone === null) {
                self::log($orderId, $status, null, ['ok' => false, 'message' => 'The customer phone number is not valid.', 'id' => null]);
                return;
            }
            self::log($orderId, $status, $phone, self::send($phone, $text));
        } catch (\Throwable $e) {
            @file_put_contents(BASE_PATH . '/storage/logs/app.log', '[' . date('c') . '] whatsapp: ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX); // never breaks an order change
        }
    }

    /** One product per line: "2 x Lure Silicone Shrimp (Pink / 3in)". */
    public static function orderedItems(int $orderId): string
    {
        $lines = [];
        foreach (OrderService::items($orderId) as $it) {
            $name = (string) $it['product_name'] . ($it['variant_label'] ? ' (' . $it['variant_label'] . ')' : '');
            $lines[] = (int) $it['qty'] . ' x ' . $name;
        }
        return implode("\n", $lines);
    }

    /** @param array{ok:bool, message:string, id:?int} $r */
    public static function log(?int $orderId, string $status, ?string $phone, array $r): void
    {
        Database::query(
            'INSERT INTO whatsapp_log (order_id, status_key, phone, ok, message_id, error) VALUES (?, ?, ?, ?, ?, ?)',
            [$orderId, $status, $phone, $r['ok'] ? 1 : 0, $r['id'], $r['ok'] ? null : mb_substr($r['message'], 0, 255)]
        );
    }

    /** @return array{code:int, json:mixed} */
    private static function http(string $method, string $url, string $token, ?string $body): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($method, $url, $token, $body);
        }
        $ch = curl_init($url);
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        return ['code' => $code, 'json' => $json];
    }
}
