<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\SecretBox;
use App\Models\Setting;

/**
 * Billplz credentials. The API secret key and X-Signature key are stored encrypted (SecretBox) and are
 * never sent back to a browser: pages only get a masked hint. The API host comes from the mode, never
 * from user input (no SSRF); BILLPLZ_BASE_URL is a deploy/test override.
 */
final class BillplzSettings
{
    public const MODES = ['sandbox' => 'Sandbox (testing)', 'production' => 'Production (live)'];
    private const BASE = ['sandbox' => 'https://www.billplz-sandbox.com/api', 'production' => 'https://www.billplz.com/api'];
    private const SECRETS = ['api_key', 'x_signature_key'];

    private static function k(string $name): string
    {
        return 'billplz.' . $name;
    }

    public static function baseUrl(string $mode): string
    {
        $override = (string) env('BILLPLZ_BASE_URL', '');
        return $override !== '' ? rtrim($override, '/') : self::BASE[$mode];
    }

    public static function mode(): string
    {
        $m = Setting::get(self::k('mode'), 'sandbox');
        return isset(self::MODES[$m]) ? $m : 'sandbox';
    }

    /** Mask: last 4 characters only, and only when the secret is long enough that this reveals little. */
    public static function hint(string $secret): string
    {
        return strlen($secret) >= 12 ? '••••' . substr($secret, -4) : '••••••••';
    }

    private static function secret(string $name): ?string
    {
        $stored = Setting::get(self::k($name));
        return $stored === '' ? null : SecretBox::decrypt($stored);
    }

    /** Safe-for-display view: no secret ever leaves this method. */
    public static function overview(): array
    {
        $out = ['mode' => self::mode(), 'collection_id' => Setting::get(self::k('collection_id')), 'unreadable' => false];
        foreach (self::SECRETS as $s) {
            $stored = Setting::get(self::k($s));
            $plain = $stored === '' ? null : SecretBox::decrypt($stored);
            $out[$s . '_set'] = $stored !== '';
            $out[$s . '_hint'] = $plain !== null ? self::hint($plain) : null;
            $out['unreadable'] = $out['unreadable'] || ($stored !== '' && $plain === null);
        }
        $out['verified'] = Setting::get(self::k('verified')); // '' | 'ok' | 'fail'
        $out['verified_at'] = Setting::get(self::k('verified_at'));
        $out['verified_msg'] = Setting::get(self::k('verified_msg'));
        return $out;
    }

    /** Decrypted credentials for the (future) checkout integration; null until fully configured. */
    public static function credentials(): ?array
    {
        $key = self::secret('api_key');
        $sig = self::secret('x_signature_key');
        $col = Setting::get(self::k('collection_id'));
        return $key !== null && $sig !== null && $col !== '' ? ['mode' => self::mode(), 'api_key' => $key, 'x_signature_key' => $sig, 'collection_id' => $col, 'base_url' => self::baseUrl(self::mode())] : null;
    }

    /** @param array{mode:string,collection_id:string,api_key:string,x_signature_key:string} $in @return array<string,string> errors */
    public static function validate(array $in): array
    {
        $errors = [];
        $current = self::overview();
        if (!isset(self::MODES[$in['mode']])) {
            $errors['mode'] = 'Choose sandbox or production.';
        }
        if (!preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $in['collection_id'])) {
            $errors['collection_id'] = 'Collection ID must be 4-64 letters, numbers, "-" or "_".';
        }
        $modeChanged = $current['collection_id'] !== '' && isset(self::MODES[$in['mode']]) && $in['mode'] !== $current['mode'];
        foreach ([['api_key', 'API secret key'], ['x_signature_key', 'X-Signature key']] as [$f, $label]) {
            $v = $in[$f];
            if ($v === '') {
                if (!$current[$f . '_set'] || $current['unreadable']) {
                    $errors[$f] = "{$label} is required.";
                } elseif ($modeChanged) {
                    $errors[$f] = "Switching mode needs the {$label} for the new mode.";
                }
            } elseif (!preg_match('/^[\x21-\x7E]{8,200}$/', $v)) {
                $errors[$f] = "{$label} looks wrong (8-200 visible characters, no spaces).";
            }
        }
        return $errors;
    }

    /**
     * Save validated input. Blank secrets keep the stored value. Any change clears the last test result.
     * @return string[] names of the fields that changed (for the audit trail)
     */
    public static function save(array $in, int $actorId): array
    {
        $before = self::overview();
        $changed = [];
        if ($in['mode'] !== $before['mode']) {
            Setting::set(self::k('mode'), $in['mode']);
            $changed[] = 'mode';
        }
        if ($in['collection_id'] !== $before['collection_id']) {
            Setting::set(self::k('collection_id'), $in['collection_id']);
            $changed[] = 'collection_id';
        }
        foreach (self::SECRETS as $s) {
            if ($in[$s] !== '') {
                Setting::set(self::k($s), SecretBox::encrypt($in[$s]));
                $changed[] = $s;
            }
        }
        if ($changed !== []) {
            foreach (['verified', 'verified_at', 'verified_msg'] as $v) {
                Setting::set(self::k($v), '');
            }
            AuditService::log($actorId, 'billplz.update', 'Changed: ' . implode(', ', $changed) . ' (secret values are never logged)');
        }
        return $changed;
    }

    /** Ask Billplz for the collection using the stored key. @return array{ok:bool,message:string} */
    public static function testConnection(int $actorId): array
    {
        $c = self::credentials();
        if ($c === null) {
            return ['ok' => false, 'message' => 'Save all three details first.'];
        }
        $ch = curl_init($c['base_url'] . '/v3/collections/' . rawurlencode($c['collection_id']));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERPWD => $c['api_key'] . ':',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (env('BILLPLZ_BASE_URL') ? CURLPROTO_HTTP : 0),
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $title = '';
        if (is_string($body) && ($j = json_decode($body, true)) && is_array($j) && isset($j['title']) && is_string($j['title'])) {
            $title = mb_substr($j['title'], 0, 80);
        }
        $result = match (true) {
            $body === false => ['ok' => false, 'message' => 'Could not reach Billplz (network error or timeout).'],
            $code === 200 => ['ok' => true, 'message' => 'Connected' . ($title !== '' ? ". Collection: {$title}." : '.')],
            $code === 401 || $code === 403 => ['ok' => false, 'message' => 'Billplz rejected the API secret key (check it matches the selected mode).'],
            $code === 404 => ['ok' => false, 'message' => 'Key accepted, but that Collection ID was not found.'],
            default => ['ok' => false, 'message' => "Billplz answered with an unexpected status ({$code})."],
        };
        Setting::set(self::k('verified'), $result['ok'] ? 'ok' : 'fail');
        Setting::set(self::k('verified_at'), date('Y-m-d H:i:s'));
        Setting::set(self::k('verified_msg'), $result['message']);
        AuditService::log($actorId, 'billplz.test', ($result['ok'] ? 'OK' : 'Failed') . ': ' . $result['message']);
        return $result;
    }
}
