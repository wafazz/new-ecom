<?php
declare(strict_types=1);

namespace App\Services\Shipping;

use App\Core\Database;
use App\Core\SecretBox;
use App\Models\Setting;
use App\Services\AuditService;
use App\Services\NinjaVanSettings;
use App\Services\OrderService;
use App\Services\PricingService;
use App\Services\ShipmentService;

/**
 * NinjaVan API (OpenAPI spec 4.1.0): OAuth token, create order (POST /{cc}/4.1/orders), cancel
 * (DELETE /{cc}/2.2/orders/{tn}), waybill (GET /{cc}/2.0/reports/waybill) and the signed webhook.
 * Malaysia domestic only for now (the address schema differs per country).
 */
final class NinjaVanGateway
{
    public const LEVELS = ['Standard' => 'Standard', 'Express' => 'Express'];
    public const SLOTS = ['09:00-18:00' => '9am - 6pm', '09:00-12:00' => '9am - 12pm', '12:00-15:00' => '12pm - 3pm', '15:00-18:00' => '3pm - 6pm', '18:00-22:00' => '6pm - 10pm', '09:00-22:00' => '9am - 10pm'];
    public const VOLUMES = ['Less than 3 Parcels', 'Less than 10 Parcels', 'Half-Van Load', 'Full-Van Load', 'Larger than Van Load', 'Trolley Required'];
    private const TZ = 'Asia/Kuala_Lumpur';
    /** Webhook statuses worth showing in the order history (the rest only go to the shipment timeline). */
    private const NOTABLE = ['Successful Pickup', 'Pickup Fail', 'On Vehicle for Delivery', 'First Attempt Delivery Fail', 'Successful Delivery', 'Completed', 'Returned to Sender', 'Cancelled'];

    // ---- input + payload (pure) -------------------------------------------------------------

    /** @return array{values:array, errors:array<string,string>} */
    public static function validateInput(array $in, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $errors = [];
        $v = [
            'weight' => trim((string) ($in['weight'] ?? '')),
            'service_level' => (string) ($in['service_level'] ?? ''),
            'delivery_date' => (string) ($in['delivery_date'] ?? ''),
            'delivery_slot' => (string) ($in['delivery_slot'] ?? ''),
            'pickup' => ($in['pickup'] ?? '') === '1',
            'pickup_date' => (string) ($in['pickup_date'] ?? ''),
            'pickup_slot' => (string) ($in['pickup_slot'] ?? ''),
            'volume' => (string) ($in['volume'] ?? ''),
        ];
        if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $v['weight']) || (float) $v['weight'] < 0.01 || (float) $v['weight'] > 50) {
            $errors['weight'] = 'Weight must be between 0.01 and 50 kg.';
        }
        if (!isset(self::LEVELS[$v['service_level']])) {
            $errors['service_level'] = 'Choose a service level.';
        }
        $date = fn(string $s, int $maxDays) => ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s)) && $d->format('Y-m-d') === $s && $d >= $today && $d <= $today->modify("+{$maxDays} days");
        if (!$date($v['delivery_date'], 14)) {
            $errors['delivery_date'] = 'Pick a delivery date from today up to 14 days ahead.';
        }
        if (!isset(self::SLOTS[$v['delivery_slot']])) {
            $errors['delivery_slot'] = 'Choose a delivery time window.';
        }
        if ($v['pickup']) {
            if (!$date($v['pickup_date'], 7)) {
                $errors['pickup_date'] = 'Pick a pickup date from today up to 7 days ahead.';
            }
            if (!isset(self::SLOTS[$v['pickup_slot']])) {
                $errors['pickup_slot'] = 'Choose a pickup time window.';
            }
            if (!in_array($v['volume'], self::VOLUMES, true)) {
                $errors['volume'] = 'Choose the approximate volume.';
            }
        }
        return ['values' => $v, 'errors' => $errors];
    }

    /** E.164 for Malaysian numbers; null when it doesn't look like one. */
    public static function phone(string $phone): ?string
    {
        $m = preg_replace('/[\s\-()]/', '', $phone);
        $m = match (true) {
            str_starts_with($m, '+') => $m,
            str_starts_with($m, '0') => '+60' . substr($m, 1),
            str_starts_with($m, '60') => '+' . $m,
            default => '+60' . $m,
        };
        return preg_match('/^\+\d{8,15}$/', $m) ? $m : null;
    }

    private static function slot(string $slot): array
    {
        [$a, $b] = explode('-', $slot);
        return ['start_time' => $a, 'end_time' => $b, 'timezone' => self::TZ];
    }

    private static function party(string $name, string $phone, string $email, array $addr): array
    {
        $name = mb_strlen($name) < 3 ? $name . ' (customer)' : $name; // NinjaVan: name minLength 3
        $p = ['name' => mb_substr($name, 0, 255), 'email' => $email, 'address' => array_filter($addr, fn($v) => $v !== null && $v !== '')];
        if (($e164 = self::phone($phone)) !== null) {
            $p['phone_number'] = $e164;
        }
        return $p;
    }

    /** The exact JSON body for POST /my/4.1/orders. */
    public static function buildPayload(array $order, array $items, array $sender, array $v, string $requestedTn): array
    {
        $job = [
            'is_pickup_required' => $v['pickup'],
            'delivery_start_date' => $v['delivery_date'],
            'delivery_timeslot' => self::slot($v['delivery_slot']),
            'allow_weekend_delivery' => true,
            'dimensions' => ['weight' => (float) $v['weight']],
            'items' => array_map(fn($it) => ['item_description' => mb_substr($it['product_name'] . ($it['variant_label'] ? ' (' . $it['variant_label'] . ')' : ''), 0, 255), 'quantity' => (int) $it['qty']], $items),
        ];
        $from = self::party($sender['sender_name'], $sender['sender_phone'], $sender['sender_email'], [
            'address1' => $sender['sender_address1'], 'address2' => $sender['sender_address2'] ?? '', 'country' => 'MY',
            'postcode' => $sender['sender_postcode'], 'city' => $sender['sender_city'], 'state' => $sender['sender_state'],
        ]);
        if ($v['pickup']) {
            $job += [
                'pickup_service_type' => 'Scheduled', 'pickup_service_level' => 'Standard', 'pickup_date' => $v['pickup_date'],
                'pickup_timeslot' => self::slot($v['pickup_slot']), 'pickup_approximate_volume' => $v['volume'], 'pickup_address' => $from,
            ];
        }
        if ($order['payment_method'] === 'cod' && $order['payment_status'] === 'unpaid') {
            $job['cash_on_delivery'] = (float) $order['total'];
        }
        if (trim((string) $order['notes']) !== '') {
            $job['delivery_instructions'] = mb_substr(trim((string) $order['notes']), 0, 500);
        }
        return [
            'service_type' => 'Parcel',
            'service_level' => $v['service_level'],
            'requested_tracking_number' => $requestedTn,
            'reference' => ['merchant_order_number' => $order['order_no']],
            'from' => $from,
            'to' => self::party($order['customer_name'], $order['customer_phone'], $order['customer_email'], [
                'address1' => $order['ship_line1'], 'address2' => $order['ship_line2'] ?? '', 'country' => 'MY',
                'postcode' => $order['ship_postcode'], 'city' => $order['ship_city'], 'state' => $order['ship_state'],
            ]),
            'parcel_job' => $job,
        ];
    }

    /**
     * Idempotency key sent as requested_tracking_number: NinjaVan allows 1-9 letters or digits and no prefix.
     * Built from the order id (unique), so the same order always asks for the same number; a retry adds a digit.
     */
    public static function requestedTracking(int $orderId, int $attempt): string
    {
        $base = 'O' . str_pad(strtoupper(base_convert((string) $orderId, 10, 36)), 6, '0', STR_PAD_LEFT);
        return $attempt <= 1 ? $base : $base . $attempt;
    }

    /** Why a shipment cannot be created for this order right now (null = fine). */
    public static function blocker(array $order): ?string
    {
        $cfg = NinjaVanSettings::overview();
        return match (true) {
            NinjaVanSettings::credentials() === null => 'NinjaVan is not configured yet. An admin needs to save the credentials in the NinjaVan settings.',
            !$cfg['sender_complete'] => 'The pickup address is missing in the NinjaVan settings.',
            $order['ship_country'] !== 'MY' => 'Shipments currently go to Malaysia addresses only.',
            $order['status'] !== 'processing' => 'Move the order to Processing before creating a shipment.',
            $order['payment_method'] !== 'cod' && $order['payment_status'] !== 'paid' => 'Payment has not been received for this order yet.',
            ShipmentService::active((int) $order['id']) !== null => 'This order already has an active shipment.',
            default => null,
        };
    }

    // ---- HTTP ---------------------------------------------------------------------------------

    /** @return array{code:int, body:string, json:?array} code 0 = network failure */
    private static function http(string $method, string $url, array $headers, ?string $body = null): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (env('NINJAVAN_BASE_URL') ? CURLPROTO_HTTP : 0),
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $raw = is_string($raw) ? $raw : '';
        $json = $raw !== '' && $raw[0] !== '%' ? json_decode($raw, true) : null;
        return ['code' => $code, 'body' => $raw, 'json' => is_array($json) ? $json : null];
    }

    private static function path(array $c, string $rest): string
    {
        return $c['base_url'] . '/' . strtolower($c['country']) . $rest;
    }

    /** Cached OAuth token (encrypted in settings, refreshed a minute before it expires). @throws \RuntimeException */
    private static function token(array $c, bool $fresh = false): string
    {
        if (!$fresh && (int) Setting::get('ninjavan.token_exp', '0') > time() + 60 && ($t = SecretBox::decrypt(Setting::get('ninjavan.token'))) !== null) {
            return $t;
        }
        $r = self::http('POST', self::path($c, '/2.0/oauth/access_token'), ['Content-Type: application/json', 'Accept: application/json'],
            json_encode(['client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'grant_type' => 'client_credentials']));
        $tok = $r['json']['access_token'] ?? null;
        if ($r['code'] !== 200 || !is_string($tok) || $tok === '') {
            self::log("token request failed: HTTP {$r['code']}");
            throw new \RuntimeException($r['code'] === 0 ? 'Could not reach NinjaVan. Please try again.' : 'NinjaVan rejected the saved credentials. An admin should re-check them in the NinjaVan settings.');
        }
        Setting::set('ninjavan.token', SecretBox::encrypt($tok));
        Setting::set('ninjavan.token_exp', (string) (time() + (int) ($r['json']['expires_in'] ?? 300)));
        return $tok;
    }

    /** Authenticated call; one automatic retry with a fresh token on 401. @throws \RuntimeException */
    private static function api(array $c, string $method, string $rest, ?array $json = null, string $accept = 'application/json'): array
    {
        for ($try = 0; $try < 2; $try++) {
            $r = self::http($method, self::path($c, $rest), ['Authorization: Bearer ' . self::token($c, $try > 0), 'Accept: ' . $accept] + ($json !== null ? [2 => 'Content-Type: application/json'] : []), $json !== null ? json_encode($json) : null);
            if ($r['code'] !== 401) {
                return $r;
            }
        }
        return $r;
    }

    /** Customer-safe summary of a NinjaVan error body. */
    private static function errorText(array $r): string
    {
        if ($r['code'] === 0) {
            return 'Could not reach NinjaVan. Please try again.';
        }
        if ($r['code'] === 429) {
            return 'NinjaVan rate limit reached. Please wait a while before trying again.';
        }
        $e = $r['json']['error'] ?? [];
        $parts = [];
        foreach (is_array($e['details'] ?? null) ? $e['details'] : [] as $d) {
            if (is_array($d) && is_string($d['message'] ?? null)) {
                $parts[] = (is_string($d['field'] ?? null) ? $d['field'] . ': ' : '') . $d['message'];
            }
        }
        $msg = is_string($e['message'] ?? null) ? $e['message'] : (is_string($e['title'] ?? null) ? $e['title'] : "HTTP {$r['code']}");
        return mb_substr($msg . ($parts ? ' (' . implode('; ', array_slice($parts, 0, 4)) . ')' : ''), 0, 300);
    }

    // ---- operations ---------------------------------------------------------------------------

    /** @return array{ok:bool, tracking?:string, error?:string} */
    public static function create(int $orderId, array $input, int $actorId): array
    {
        $check = self::validateInput($input);
        if ($check['errors'] !== []) {
            return ['ok' => false, 'error' => implode(' ', $check['errors']), 'fields' => $check['errors']];
        }
        $v = $check['values'];
        $c = NinjaVanSettings::credentials();
        $sender = NinjaVanSettings::sender();
        if ($c === null || $sender === null) {
            return ['ok' => false, 'error' => 'NinjaVan is not fully configured.'];
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $order = Database::query('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId])->fetch();
            if (!$order || ($why = self::blocker($order)) !== null) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => $order ? $why : 'Order not found.'];
            }
            $attempt = 1 + (int) Database::query('SELECT COUNT(*) FROM shipments WHERE order_id = ?', [$orderId])->fetchColumn();
            $requested = self::requestedTracking((int) $order['id'], $attempt);
            $items = OrderService::items($orderId);
            $payload = self::buildPayload($order, $items, $sender, $v, $requested);

            $r = self::api($c, 'POST', '/4.1/orders', $payload);
            $tn = $r['json']['tracking_number'] ?? null;
            if ($r['code'] !== 200 && $r['code'] !== 201 || !is_string($tn) || !preg_match('/^[A-Za-z0-9\-]{4,60}$/', $tn)) {
                $pdo->rollBack();
                self::log("create failed for {$order['order_no']}: HTTP {$r['code']}");
                $dup = $r['code'] === 400 && stripos(json_encode($r['json']), 'duplicate') !== false;
                return ['ok' => false, 'error' => $dup
                    ? "NinjaVan already has a shipment with this order's reference (an earlier attempt may have succeeded). Check the Ninja Dashboard for {$requested} before trying again."
                    : 'NinjaVan could not create the shipment: ' . self::errorText($r)];
            }

            $cod = $payload['parcel_job']['cash_on_delivery'] ?? null;
            try {
                Database::query(
                    'INSERT INTO shipments (order_id, tracking_number, requested_tracking_number, service_level, weight_kg, cod_amount, pickup_required, pickup_date, pickup_timeslot, delivery_date, delivery_timeslot, status, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$orderId, $tn, $requested, $v['service_level'], $v['weight'], $cod === null ? null : PricingService::format((int) round($cod * 100)),
                     $v['pickup'] ? 1 : 0, $v['pickup'] ? $v['pickup_date'] : null, $v['pickup'] ? $v['pickup_slot'] : null, $v['delivery_date'], $v['delivery_slot'], 'Created', $actorId]
                );
            } catch (\Throwable $e) {
                // The parcel exists at NinjaVan but we could not record it: leave a loud trail for recovery.
                self::log("CREATED at NinjaVan but NOT saved locally: order {$order['order_no']} tracking {$tn}: " . $e->getMessage());
                throw $e;
            }
            OrderService::note($orderId, 'shipment_created', "NinjaVan shipment {$tn} ({$v['service_level']}, {$v['weight']} kg)");
            $pdo->commit();
            AuditService::log($actorId, 'ninjavan.shipment', "Created {$tn} for {$order['order_no']}");
            return ['ok' => true, 'tracking' => $tn];
        } catch (\RuntimeException $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @return array{ok:bool, error?:string} */
    public static function cancel(int $orderId, int $actorId): array
    {
        $c = NinjaVanSettings::credentials();
        $s = ShipmentService::active($orderId);
        if ($c === null || $s === null) {
            return ['ok' => false, 'error' => 'There is no active shipment to cancel.'];
        }
        try {
            $r = self::api($c, 'DELETE', '/2.2/orders/' . rawurlencode($s['tracking_number']));
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($r['code'] !== 200) {
            self::log("cancel failed for {$s['tracking_number']}: HTTP {$r['code']}");
            return ['ok' => false, 'error' => 'NinjaVan could not cancel it' . ($r['code'] === 404 || $r['code'] === 400 ? ' (only parcels still Pending Pickup can be cancelled)' : '') . ': ' . self::errorText($r)];
        }
        self::markCancelled((int) $s['id'], $orderId, "NinjaVan shipment {$s['tracking_number']} cancelled", $actorId);
        AuditService::log($actorId, 'ninjavan.shipment', "Cancelled {$s['tracking_number']}");
        return ['ok' => true];
    }

    private static function markCancelled(int $shipmentId, int $orderId, string $note, ?int $actorId): void
    {
        Database::query("UPDATE shipments SET active = NULL, cancelled_at = NOW(), status = 'Cancelled' WHERE id = ? AND active = 1", [$shipmentId]);
        Database::query('INSERT INTO order_events (order_id, event, note, actor_id) VALUES (?, ?, ?, ?)', [$orderId, 'shipment_cancelled', $note, $actorId]);
    }

    /** Cached PDF waybill (NinjaVan asks integrators to cache it). @return array{ok:bool, file?:string, error?:string} */
    public static function waybill(array $shipment): array
    {
        $tn = (string) $shipment['tracking_number'];
        if (!preg_match('/^[A-Za-z0-9\-]{4,60}$/', $tn)) {
            return ['ok' => false, 'error' => 'Invalid tracking number.'];
        }
        $dir = BASE_PATH . '/storage/waybills';
        $file = "{$dir}/{$tn}.pdf";
        if (is_file($file)) {
            return ['ok' => true, 'file' => $file];
        }
        $c = NinjaVanSettings::credentials();
        if ($c === null) {
            return ['ok' => false, 'error' => 'NinjaVan is not configured.'];
        }
        try {
            $r = self::api($c, 'GET', '/2.0/reports/waybill?' . http_build_query(['tids' => $tn, 'h' => 0, 'orientation' => 'portrait']), null, 'application/pdf');
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($r['code'] !== 200 || !str_starts_with($r['body'], '%PDF')) {
            self::log("waybill failed for {$tn}: HTTP {$r['code']}");
            return ['ok' => false, 'error' => 'The waybill is not available yet (NinjaVan makes it available once the order is Pending Pickup, and the waybill API must be enabled on your account by NinjaVan support). ' . ($r['code'] === 0 ? 'Could not reach NinjaVan.' : '')];
        }
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Cannot store the waybill.'];
        }
        file_put_contents($file, $r['body'], LOCK_EX);
        return ['ok' => true, 'file' => $file];
    }

    // ---- webhook ------------------------------------------------------------------------------

    /** X-Ninjavan-Hmac-SHA256: base64( HMAC-SHA256(raw body, Client Key) ). */
    public static function webhookValid(string $rawBody, ?string $header, string $clientSecret): bool
    {
        return is_string($header) && $header !== '' && hash_equals(base64_encode(hash_hmac('sha256', $rawBody, $clientSecret, true)), $header);
    }

    /** Record one tracking event. Idempotent (retries) and order-safe (older events never overwrite a newer status). @return string 'recorded'|'duplicate'|'unknown' */
    public static function recordEvent(array $p): string
    {
        $tn = is_string($p['tracking_id'] ?? null) ? $p['tracking_id'] : '';
        $status = is_string($p['status'] ?? null) ? mb_substr($p['status'], 0, 60) : '';
        $shipment = $tn === '' || $status === '' ? null : Database::query('SELECT * FROM shipments WHERE tracking_number = ?', [$tn])->fetch();
        if (!$shipment) {
            return 'unknown';
        }
        $ts = is_string($p['timestamp'] ?? null) ? strtotime($p['timestamp']) : false;
        $when = date('Y-m-d H:i:s', $ts === false ? time() : $ts);
        $uid = is_scalar($p['id'] ?? null) ? mb_substr((string) $p['id'], 0, 64) : null;
        $prev = is_string($p['previous_status'] ?? null) ? mb_substr($p['previous_status'], 0, 60) : null;
        $comments = is_string($p['comments'] ?? null) && $p['comments'] !== '' ? mb_substr($p['comments'], 0, 255) : null;

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $ins = Database::query('INSERT IGNORE INTO shipment_events (shipment_id, event_uid, status, previous_status, comments, occurred_at) VALUES (?,?,?,?,?,?)', [$shipment['id'], $uid, $status, $prev, $comments, $when]);
            if ($ins->rowCount() === 0) {
                $pdo->rollBack();
                return 'duplicate';
            }
            if ($shipment['last_event_at'] === null || $when >= $shipment['last_event_at']) {
                Database::query('UPDATE shipments SET status = ?, last_event_at = ? WHERE id = ?', [$status, $when, $shipment['id']]);
            }
            if ($status === 'Cancelled' && (int) $shipment['active'] === 1) {
                self::markCancelled((int) $shipment['id'], (int) $shipment['order_id'], "NinjaVan shipment {$tn} was cancelled by the courier", null);
            } elseif (in_array($status, self::NOTABLE, true)) {
                OrderService::note((int) $shipment['order_id'], 'shipment_update', "NinjaVan: {$status}" . ($comments !== null ? " ({$comments})" : ''));
            }
            $pdo->commit();
            return 'recorded';
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function log(string $msg): void
    {
        @file_put_contents(BASE_PATH . '/storage/logs/app.log', '[' . date('c') . '] ninjavan: ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    }
}
