<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\SecretBox;
use App\Models\Setting;

/**
 * NinjaVan courier settings (API docs: api-docs.ninjavan.co; OpenAPI servers: api-sandbox.ninjavan.co / api.ninjavan.co).
 * Client ID + Client Key (OAuth client credentials) and the sender/pickup address that every shipment needs.
 * The Client Key is stored encrypted (SecretBox) and never rendered back. The same key signs NinjaVan's
 * webhooks (X-Ninjavan-Hmac-SHA256), so there is no separate webhook secret to store.
 */
final class NinjaVanSettings
{
    public const MODES = ['sandbox' => 'Sandbox (testing)', 'production' => 'Production (live)'];
    public const COUNTRIES = ['MY' => 'Malaysia', 'SG' => 'Singapore', 'TH' => 'Thailand', 'ID' => 'Indonesia', 'VN' => 'Viet Nam', 'PH' => 'Philippines', 'MM' => 'Myanmar'];
    public const SENDER = ['sender_name', 'sender_phone', 'sender_email', 'sender_address1', 'sender_address2', 'sender_postcode', 'sender_city', 'sender_state'];
    private const BASE = ['sandbox' => 'https://api-sandbox.ninjavan.co', 'production' => 'https://api.ninjavan.co'];

    private static function k(string $name): string
    {
        return 'ninjavan.' . $name;
    }

    /** Host from the mode only (no user-supplied URL); NINJAVAN_BASE_URL is a deploy/test override. */
    public static function baseUrl(string $mode): string
    {
        $override = (string) env('NINJAVAN_BASE_URL', '');
        return $override !== '' ? rtrim($override, '/') : self::BASE[$mode];
    }

    /** HQ choice: print the ordered items on the AWB label (off by default). */
    public static function showItemsOnAwb(): bool
    {
        return Setting::get(self::k('awb_show_items'), '0') === '1';
    }

    public static function mode(): string
    {
        $m = Setting::get(self::k('mode'), 'sandbox');
        return isset(self::MODES[$m]) ? $m : 'sandbox';
    }

    public static function country(): string
    {
        $c = Setting::get(self::k('country'), 'MY');
        return isset(self::COUNTRIES[$c]) ? $c : 'MY';
    }

    public static function hint(string $secret): string
    {
        return strlen($secret) >= 12 ? '••••' . substr($secret, -4) : '••••••••';
    }

    /** Safe-for-display view: the Client Key never leaves this method, only a masked hint. */
    public static function overview(): array
    {
        $stored = Setting::get(self::k('client_secret'));
        $plain = $stored === '' ? null : SecretBox::decrypt($stored);
        $out = [
            'mode' => self::mode(), 'country' => self::country(), 'client_id' => Setting::get(self::k('client_id')),
            'client_secret_set' => $stored !== '', 'client_secret_hint' => $plain !== null ? self::hint($plain) : null,
            'unreadable' => $stored !== '' && $plain === null,
            'verified' => Setting::get(self::k('verified')), 'verified_at' => Setting::get(self::k('verified_at')), 'verified_msg' => Setting::get(self::k('verified_msg')),
            'awb_show_items' => self::showItemsOnAwb(),
        ];
        foreach (self::SENDER as $f) {
            $out[$f] = Setting::get(self::k($f));
        }
        $out['sender_complete'] = self::senderComplete($out);
        return $out;
    }

    private static function senderComplete(array $o): bool
    {
        foreach (['sender_name', 'sender_phone', 'sender_email', 'sender_address1', 'sender_postcode', 'sender_city', 'sender_state'] as $f) {
            if (($o[$f] ?? '') === '') {
                return false;
            }
        }
        return true;
    }

    /** Decrypted credentials for server-side use (shipment creation / webhook verification); null until configured. */
    public static function credentials(): ?array
    {
        $stored = Setting::get(self::k('client_secret'));
        $secret = $stored === '' ? null : SecretBox::decrypt($stored);
        $id = Setting::get(self::k('client_id'));
        return $secret !== null && $id !== '' ? ['mode' => self::mode(), 'country' => self::country(), 'client_id' => $id, 'client_secret' => $secret, 'base_url' => self::baseUrl(self::mode())] : null;
    }

    /** The shipper's pickup address, or null while incomplete. */
    public static function sender(): ?array
    {
        $o = self::overview();
        return $o['sender_complete'] ? array_intersect_key($o, array_flip(self::SENDER)) + ['country' => $o['country']] : null;
    }

    /** @param array<string,string> $in @return array<string,string> errors */
    public static function validate(array $in): array
    {
        $errors = [];
        $cur = self::overview();
        if (!isset(self::MODES[$in['mode']])) {
            $errors['mode'] = 'Choose sandbox or production.';
        }
        if (!isset(self::COUNTRIES[$in['country']])) {
            $errors['country'] = 'Choose a supported country.';
        }
        if (!preg_match('/^[A-Za-z0-9_\-.]{4,100}$/', $in['client_id'])) {
            $errors['client_id'] = 'Client ID must be 4-100 letters, numbers, "-", "_" or ".".';
        }

        $secret = $in['client_secret'];
        $identityChanged = $cur['client_id'] !== '' && ($in['mode'] !== $cur['mode'] || $in['country'] !== $cur['country'] || $in['client_id'] !== $cur['client_id']);
        if ($secret === '') {
            if (!$cur['client_secret_set'] || $cur['unreadable']) {
                $errors['client_secret'] = 'Client Key is required.';
            } elseif ($identityChanged) {
                $errors['client_secret'] = 'Changing the mode, country or Client ID needs the Client Key again.';
            }
        } elseif (!preg_match('/^[\x21-\x7E]{8,200}$/', $secret)) {
            $errors['client_secret'] = 'Client Key looks wrong (8-200 visible characters, no spaces).';
        }

        // Sender: all-or-nothing. Leaving everything blank is allowed (set it before shipping).
        $filled = array_filter(array_intersect_key($in, array_flip(self::SENDER)), fn($v) => $v !== '');
        $onlyOptional = array_keys($filled) === ['sender_address2'];
        if ($filled !== [] && !$onlyOptional) {
            foreach (['sender_name' => 'Sender name', 'sender_phone' => 'Sender phone', 'sender_email' => 'Sender email', 'sender_address1' => 'Address line 1', 'sender_city' => 'City', 'sender_postcode' => 'Postcode', 'sender_state' => 'State'] as $f => $label) {
                if ($in[$f] === '') {
                    $errors[$f] = "{$label} is required once you fill in the pickup address.";
                }
            }
            $len = ['sender_name' => 120, 'sender_address1' => 190, 'sender_address2' => 190, 'sender_city' => 100, 'sender_state' => 60, 'sender_email' => 190];
            foreach ($len as $f => $max) {
                if (!isset($errors[$f]) && mb_strlen($in[$f]) > $max) {
                    $errors[$f] = "Must be at most {$max} characters.";
                }
            }
            if (!isset($errors['sender_phone']) && !preg_match('/^\+?[0-9][0-9 \-]{6,}$/', $in['sender_phone'])) {
                $errors['sender_phone'] = 'Phone must be a valid number, e.g. 012-3456789.';
            }
            if (!isset($errors['sender_email']) && !filter_var($in['sender_email'], FILTER_VALIDATE_EMAIL)) {
                $errors['sender_email'] = 'Enter a valid email.';
            }
            if ($in['country'] === 'MY') {
                if (!isset($errors['sender_postcode']) && !preg_match('/^\d{5}$/', $in['sender_postcode'])) {
                    $errors['sender_postcode'] = 'Postcode must be 5 digits.';
                }
                if (!isset($errors['sender_state']) && !in_array($in['sender_state'], config('states'), true)) {
                    $errors['sender_state'] = 'Choose a state from the list.';
                }
            } elseif (!isset($errors['sender_postcode']) && !preg_match('/^[A-Za-z0-9 \-]{3,10}$/', $in['sender_postcode'])) {
                $errors['sender_postcode'] = 'Enter a valid postcode.';
            }
        }
        return $errors;
    }

    /** @return string[] changed field names (for the audit trail; secret values are never logged) */
    public static function save(array $in, int $actorId): array
    {
        $before = self::overview();
        $changed = [];
        foreach (['mode', 'country', 'client_id', ...self::SENDER] as $f) {
            if ($in[$f] !== $before[$f]) {
                Setting::set(self::k($f), $in[$f]);
                $changed[] = $f;
            }
        }
        $showItems = ($in['awb_show_items'] ?? '0') === '1';
        if ($showItems !== $before['awb_show_items']) {
            Setting::set(self::k('awb_show_items'), $showItems ? '1' : '0');
            $changed[] = 'awb_show_items';
        }
        if ($in['client_secret'] !== '') {
            Setting::set(self::k('client_secret'), SecretBox::encrypt($in['client_secret']));
            $changed[] = 'client_secret';
        }
        // A new test only matters when the credentials themselves changed; sender edits keep the last result.
        if (array_intersect($changed, ['mode', 'country', 'client_id', 'client_secret']) !== []) {
            Setting::set(self::k('token'), '');
            Setting::set(self::k('token_exp'), '0');
            foreach (['verified', 'verified_at', 'verified_msg'] as $v) {
                Setting::set(self::k($v), '');
            }
        }
        if ($changed !== []) {
            AuditService::log($actorId, 'ninjavan.update', 'Changed: ' . implode(', ', $changed) . ' (the Client Key value is never logged)');
        }
        return $changed;
    }

    /** Request an OAuth token with the saved credentials (the token itself is discarded). @return array{ok:bool,message:string} */
    public static function testConnection(int $actorId): array
    {
        $c = self::credentials();
        if ($c === null) {
            return ['ok' => false, 'message' => 'Save the Client ID and Client Key first.'];
        }
        $ch = curl_init($c['base_url'] . '/' . strtolower($c['country']) . '/2.0/oauth/access_token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'grant_type' => 'client_credentials']),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (env('NINJAVAN_BASE_URL') ? CURLPROTO_HTTP : 0),
        ]);
        $body = curl_exec($ch);
        $code = $body === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $json = is_string($body) ? json_decode($body, true) : null;
        $token = is_array($json) && is_string($json['access_token'] ?? null) && $json['access_token'] !== '';
        $mins = is_array($json) && is_int($json['expires_in'] ?? null) ? intdiv($json['expires_in'], 60) : null;
        $result = match (true) {
            $body === false => ['ok' => false, 'message' => 'Could not reach NinjaVan (network error or timeout).'],
            $code === 200 && $token => ['ok' => true, 'message' => 'Connected: NinjaVan accepted the credentials' . ($mins !== null ? " (token valid {$mins} min)." : '.')],
            in_array($code, [400, 401, 403], true) => ['ok' => false, 'message' => 'NinjaVan rejected the Client ID / Client Key (check they match the selected mode and country).'],
            $code === 200 => ['ok' => false, 'message' => 'NinjaVan answered without an access token.'],
            default => ['ok' => false, 'message' => "NinjaVan answered with an unexpected status ({$code})."],
        };
        Setting::set(self::k('verified'), $result['ok'] ? 'ok' : 'fail');
        Setting::set(self::k('verified_at'), date('Y-m-d H:i:s'));
        Setting::set(self::k('verified_msg'), $result['message']);
        AuditService::log($actorId, 'ninjavan.test', ($result['ok'] ? 'OK' : 'Failed') . ': ' . $result['message']);
        return $result;
    }
}
