<?php
declare(strict_types=1);

namespace App\Services\Shipping;

use App\Core\Database;
use App\Services\AuditService;
use App\Services\JntExpressSettings;
use App\Services\NinjaVanSettings;
use App\Services\OrderService;
use App\Services\PricingService;
use App\Services\ShipmentService;

/**
 * J&T Express Malaysia: create order (POST order/addOrder) for a Processing order.
 *
 * The request shape and signature follow the J&T Malaysia Open Platform order spec as transcribed by
 * community SDKs (see docs in the J&T notes). Not yet verified against a live J&T sandbox: test in
 * sandbox mode first and check the first order in the J&T portal.
 *   digest    = base64(md5(bizContent JSON . private key, binary))
 *   password  = strtoupper(md5(account password))   (sent inside bizContent)
 * Headers: apiAccount, digest, timestamp (ms). Body: form field bizContent = the JSON.
 */
final class JntExpressGateway
{
    private const BASE = ['sandbox' => 'https://demoopenapi.jtexpress.my/webopenplatformapi/api', 'production' => 'https://ylopenapi.jtexpress.my/webopenplatformapi/api'];
    private const TIMEOUT = 20;

    // ---- pure builders ---------------------------------------------------------------------

    /** Digest header for a bizContent JSON string. */
    public static function digest(string $bizContentJson, string $privateKey): string
    {
        return base64_encode(md5($bizContentJson . $privateKey, true));
    }

    /** Account password as J&T expects it inside bizContent. */
    public static function passwordParam(string $plain): string
    {
        return strtoupper(md5($plain));
    }

    /** Whole ringgit/sen string, 2 decimals, e.g. "12.50". */
    private static function money(float $v): string
    {
        return number_format(round($v, 2), 2, '.', '');
    }

    /**
     * The bizContent object for an order. $items rows need product_name, variant_label, qty, unit_price, weight_kg.
     * Returns null when the order cannot be described (missing weight), so nothing is sent half-formed.
     */
    public static function buildBizContent(array $order, array $items, array $sender, string $customerCode, string $password): ?array
    {
        $kg = 0.0;
        $lines = [];
        $value = 0.0;
        foreach ($items as $it) {
            $w = (float) ($it['weight_kg'] ?? 0);
            if ($w <= 0) {
                return null;
            }
            $kg += $w * (int) $it['qty'];
            $value += (float) $it['unit_price'] * (int) $it['qty'];
            $name = mb_substr($it['product_name'] . ($it['variant_label'] ? ' (' . $it['variant_label'] . ')' : ''), 0, 100);
            $lines[] = [
                'itemName' => $name,
                'number' => (string) (int) $it['qty'],
                // Per-item weight in grams (the spec gives no unit; confirm in sandbox).
                'weight' => (string) max(1, (int) round($w * 1000)),
                'itemValue' => self::money((float) $it['unit_price']),
                'itemCurrency' => 'MYR',
                'itemDesc' => $name,
            ];
        }
        if ($lines === [] || $value <= 0) {
            return null;
        }
        $cod = $order['payment_method'] === 'cod' && $order['payment_status'] === 'unpaid';
        $biz = [
            'customerCode' => $customerCode,
            'password' => self::passwordParam($password),
            'txlogisticId' => (string) $order['order_no'],
            'actionType' => 'add',
            'serviceType' => '1', // 1 = J&T collects from the shop's pickup address
            'payType' => $cod ? 'CC_CASH' : 'PP_PM',
            'expressType' => 'EZ',
            'sender' => [
                'name' => mb_substr((string) $sender['sender_name'], 0, 100),
                'phone' => mb_substr((string) $sender['sender_phone'], 0, 30),
                'countryCode' => 'MYS',
                'address' => mb_substr(trim($sender['sender_address1'] . ' ' . ($sender['sender_address2'] ?? '')), 0, 200),
                'postCode' => (string) $sender['sender_postcode'],
            ],
            'receiver' => array_filter([
                'name' => mb_substr((string) $order['customer_name'], 0, 100),
                'phone' => mb_substr((string) $order['customer_phone'], 0, 30),
                'email' => (string) $order['customer_email'] ?: null,
                'countryCode' => 'MYS',
                'prov' => mb_substr((string) $order['ship_state'], 0, 60),
                'city' => mb_substr((string) $order['ship_city'], 0, 60),
                'address' => mb_substr(trim($order['ship_line1'] . ' ' . ($order['ship_line2'] ?? '')), 0, 200),
                'postCode' => (string) $order['ship_postcode'],
            ], fn($v) => $v !== null && $v !== ''),
            'items' => $lines,
            'packageInfo' => [
                'packageQuantity' => '1',
                'weight' => self::money(max(0.01, round($kg, 2))),
                'packageValue' => self::money($value),
                'goodsType' => 'ITN8',
            ],
        ];
        if ($cod) {
            $biz['codInfo'] = ['codValue' => self::money((float) $order['total'])];
        }
        if (trim((string) $order['notes']) !== '') {
            $biz['remark'] = mb_substr(trim((string) $order['notes']), 0, 200);
        }
        return $biz;
    }

    // ---- availability ----------------------------------------------------------------------

    /** Why an order can't be pushed to J&T right now, or null when it can. */
    public static function blocker(array $order): ?string
    {
        $o = JntExpressSettings::overview();
        $sender = NinjaVanSettings::sender();
        return match (true) {
            !$o['enabled'] => 'J&T Express is not turned on. Enable it in API Management.',
            !$o['complete'] => 'J&T Express is not fully configured. Save the API account, customer code and secrets first.',
            $sender === null => 'The pickup address is missing. Fill it in under API Management → NinjaVan.',
            $order['ship_country'] !== 'MY' => 'J&T pushes here are for Malaysia addresses only.',
            $order['status'] !== 'processing' => 'Move the order to Processing before pushing it.',
            $order['payment_method'] !== 'cod' && $order['payment_status'] !== 'paid' => 'Payment has not been received for this order yet.',
            ShipmentService::active((int) $order['id']) !== null => 'This order already has an active shipment.',
            default => null,
        };
    }

    // ---- create ----------------------------------------------------------------------------

    /** @return array{ok:bool,error?:string,tracking?:string} */
    public static function create(int $orderId, int $actorId): array
    {
        $creds = JntExpressSettings::credentials();
        $sender = NinjaVanSettings::sender();
        if ($creds === null || $sender === null) {
            return ['ok' => false, 'error' => 'J&T Express is not fully configured.'];
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $order = Database::query('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId])->fetch();
            if (!$order || ($why = self::blocker($order)) !== null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => $order ? $why : 'Order not found.'];
            }
            $items = Database::query(
                'SELECT oi.*, p.weight_kg FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY oi.id',
                [$orderId]
            )->fetchAll();
            if ($items === []) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'This order has no items, so there is nothing to send to J&T.'];
            }
            $biz = self::buildBizContent($order, $items, $sender, $creds['customer_code'], $creds['password']);
            if ($biz === null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Every product needs a weight before it can be sent to J&T. Check the product weights.'];
            }

            $json = json_encode($biz, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $r = self::post($creds, $json);
            $code = (string) ($r['json']['code'] ?? '');
            $tn = $r['json']['data']['billCode'] ?? null;
            if ($r['http'] !== 200 || $code !== '1' || !is_string($tn) || $tn === '') {
                $pdo->rollBack();
                $msg = is_array($r['json'] ?? null) ? (string) ($r['json']['msg'] ?? '') : '';
                self::log("create failed for {$order['order_no']}: HTTP {$r['http']} code {$code} {$msg}");
                return ['ok' => false, 'error' => 'J&T could not create the order' . ($msg !== '' ? ": {$msg}" : " (HTTP {$r['http']}).")];
            }

            $cod = $biz['codInfo']['codValue'] ?? null;
            try {
                Database::query(
                    'INSERT INTO shipments (order_id, carrier, tracking_number, sorting_code, third_sorting_code, service_level, weight_kg, cod_amount, pickup_required, status, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [$orderId, 'jnt', $tn, self::code($r['json']['data']['sortingCode'] ?? null, 20), self::code($r['json']['data']['thirdSortingCode'] ?? null, 10),
                     null, $biz['packageInfo']['weight'], $cod === null ? null : PricingService::format((int) round((float) $cod * 100)), 0, 'Created', $actorId]
                );
            } catch (\Throwable $e) {
                self::log("CREATED at J&T but NOT saved locally: order {$order['order_no']} billCode {$tn}: " . $e->getMessage());
                throw $e;
            }
            OrderService::note($orderId, 'shipment_created', "J&T Express shipment {$tn} ({$biz['packageInfo']['weight']} kg)");
            $pdo->commit();
            AuditService::log($actorId, 'jnt.shipment', "Created {$tn} for {$order['order_no']}");
            return ['ok' => true, 'tracking' => $tn];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if (!$e instanceof \RuntimeException) {
                throw $e;
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** A sorting code from J&T's response, trimmed to its column size; null when absent. */
    private static function code(mixed $v, int $max): ?string
    {
        return is_string($v) && $v !== '' ? mb_substr($v, 0, $max) : null;
    }

    /** One signed POST to order/addOrder. @return array{http:int,json:?array} */
    private static function post(array $creds, string $bizJson): array
    {
        $base = (string) env('JNT_BASE_URL', '') !== '' ? rtrim((string) env('JNT_BASE_URL'), '/') : self::BASE[$creds['mode']];
        $ts = (string) (int) round(microtime(true) * 1000);
        $ch = curl_init($base . '/order/addOrder');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | ((string) env('JNT_BASE_URL', '') !== '' ? CURLPROTO_HTTP : 0),
            CURLOPT_POSTFIELDS => http_build_query(['bizContent' => $bizJson]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'apiAccount: ' . $creds['api_account'],
                'digest: ' . self::digest($bizJson, $creds['private_key']),
                'timestamp: ' . $ts,
            ],
        ]);
        $body = curl_exec($ch);
        $http = $body === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = is_string($body) ? json_decode($body, true) : null;
        return ['http' => $http, 'json' => is_array($json) ? $json : null];
    }

    private static function log(string $msg): void
    {
        @file_put_contents(BASE_PATH . '/storage/logs/app.log', '[' . date('c') . '] jnt: ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    }
}
