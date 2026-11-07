<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\SecretBox;
use App\Models\Setting;

/**
 * J&T Express (Malaysia) courier credentials. Stored like NinjaVan: plain fields in settings, the
 * secrets (Private Key and account Password) encrypted with SecretBox and never rendered back.
 * Shipment and tracking calls are not wired yet, so nothing here talks to J&T.
 */
final class JntExpressSettings
{
    public const MODES = ['sandbox' => 'Sandbox (testing)', 'production' => 'Production (live)'];
    private const SECRETS = ['private_key', 'password'];

    private static function k(string $name): string
    {
        return 'jnt.' . $name;
    }

    public static function mode(): string
    {
        $m = Setting::get(self::k('mode'), 'sandbox');
        return isset(self::MODES[$m]) ? $m : 'sandbox';
    }

    public static function hint(string $secret): string
    {
        return strlen($secret) >= 12 ? '••••' . substr($secret, -4) : '••••••••';
    }

    /** Safe-for-display view: secrets leave this method only as a masked hint. */
    public static function overview(): array
    {
        $out = [
            'enabled' => Setting::get(self::k('enabled'), '0') === '1',
            'mode' => self::mode(),
            'api_account' => Setting::get(self::k('api_account')),
            'customer_code' => Setting::get(self::k('customer_code')),
        ];
        foreach (self::SECRETS as $s) {
            $stored = Setting::get(self::k($s));
            $plain = $stored === '' ? null : SecretBox::decrypt($stored);
            $out[$s . '_set'] = $stored !== '';
            $out[$s . '_hint'] = $plain !== null ? self::hint($plain) : null;
            $out[$s . '_unreadable'] = $stored !== '' && $plain === null;
        }
        $out['unreadable'] = $out['private_key_unreadable'] || $out['password_unreadable'];
        $out['complete'] = $out['api_account'] !== '' && $out['customer_code'] !== '' && $out['private_key_set'] && $out['password_set'] && !$out['unreadable'];
        return $out;
    }

    /** True when the admin switched J&T on and the credentials are complete: shows the Push button and allows pushes. */
    public static function enabled(): bool
    {
        $o = self::overview();
        return $o['enabled'] && $o['complete'];
    }

    /** Decrypted credentials for server-side use; null until everything needed is saved and readable. */
    public static function credentials(): ?array
    {
        $o = self::overview();
        if (!$o['complete']) {
            return null;
        }
        $secrets = [];
        foreach (self::SECRETS as $s) {
            $secrets[$s] = SecretBox::decrypt(Setting::get(self::k($s)));
        }
        return ['mode' => $o['mode'], 'api_account' => $o['api_account'], 'customer_code' => $o['customer_code']] + $secrets;
    }

    /** @param array<string,string> $in @return array<string,string> errors */
    public static function validate(array $in, array $current): array
    {
        $errors = [];
        if (!isset(self::MODES[$in['mode']])) {
            $errors['mode'] = 'Choose sandbox or production.';
        }
        if (!preg_match('/^[A-Za-z0-9_\-.]{1,100}$/', $in['api_account'])) {
            $errors['api_account'] = 'API account must be 1-100 letters, numbers, "-", "_" or ".".';
        }
        if ($in['customer_code'] === '' || mb_strlen($in['customer_code']) > 100) {
            $errors['customer_code'] = 'Customer code is required (at most 100 characters).';
        }
        $secretLabels = ['private_key' => 'Private Key', 'password' => 'Password'];
        foreach (self::SECRETS as $s) {
            $v = $in[$s];
            if ($v === '') {
                if (!$current[$s . '_set'] || $current[$s . '_unreadable']) {
                    $errors[$s] = $secretLabels[$s] . ' is required.';
                }
            } elseif (!preg_match('/^[\x21-\x7E]{4,200}$/', $v)) {
                $errors[$s] = $secretLabels[$s] . ' looks wrong (4-200 visible characters, no spaces).';
            }
        }
        return $errors;
    }

    /** @return string[] changed field names (secret values are never logged) */
    public static function save(array $in, int $actorId): array
    {
        $before = self::overview();
        $changed = [];
        foreach (['mode', 'api_account', 'customer_code'] as $f) {
            if ($in[$f] !== $before[$f]) {
                Setting::set(self::k($f), $in[$f]);
                $changed[] = $f;
            }
        }
        $on = ($in['enabled'] ?? '0') === '1' ? '1' : '0';
        if ($on !== ($before['enabled'] ? '1' : '0')) {
            Setting::set(self::k('enabled'), $on);
            $changed[] = 'enabled';
        }
        foreach (self::SECRETS as $s) {
            if ($in[$s] !== '') {
                Setting::set(self::k($s), SecretBox::encrypt($in[$s]));
                $changed[] = $s;
            }
        }
        if ($changed !== []) {
            AuditService::log($actorId, 'jnt.update', 'Changed: ' . implode(', ', $changed) . ' (secret values are never logged)');
        }
        return $changed;
    }
}
