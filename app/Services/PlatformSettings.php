<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\SecretBox;
use App\Models\Setting;

/**
 * The superadmin's own payment details: the Billplz account that receives module payments, and the bank account for
 * receipt transfers. Secret keys are stored encrypted and are never sent back to a browser, only a masked hint.
 * Separate from the shop's sales Billplz (BillplzSettings).
 */
final class PlatformSettings
{
    private const SECRETS = ['billplz_api_key', 'billplz_x_signature_key'];
    public const MODES = ['sandbox' => 'Sandbox (testing)', 'production' => 'Production (live)'];

    private static function k(string $name): string
    {
        return 'platform.' . $name;
    }

    public static function mask(string $secret): string
    {
        return strlen($secret) >= 12 ? '••••' . substr($secret, -4) : '••••••••';
    }

    /** Display values only: secrets as masked hints. */
    public static function overview(): array
    {
        $out = [];
        foreach (self::SECRETS as $name) {
            $stored = Setting::get(self::k($name));
            $plain = $stored === '' ? null : SecretBox::decrypt($stored);
            $out[$name] = ['set' => $plain !== null, 'hint' => $plain === null ? '' : self::mask($plain)];
        }
        foreach (['billplz_mode', 'billplz_collection_id', 'bank_name', 'bank_account_number', 'bank_account_name'] as $name) {
            $out[$name] = Setting::get(self::k($name));
        }
        return $out;
    }

    /**
     * Billplz credentials for module invoices, or null when they are not complete.
     * @return array{mode:string, api_key:string, x_signature_key:string, collection_id:string, base_url:string}|null
     */
    public static function billplzCredentials(): ?array
    {
        $key = self::secret('billplz_api_key');
        $sig = self::secret('billplz_x_signature_key');
        $col = Setting::get(self::k('billplz_collection_id'));
        if ($key === null || $sig === null || $col === '') {
            return null;
        }
        $mode = self::mode();
        return ['mode' => $mode, 'api_key' => $key, 'x_signature_key' => $sig, 'collection_id' => $col, 'base_url' => BillplzSettings::baseUrl($mode)];
    }

    /** Bank details for receipt transfers, or null when not set. */
    public static function bank(): ?array
    {
        $name = Setting::get(self::k('bank_name'));
        $number = Setting::get(self::k('bank_account_number'));
        $holder = Setting::get(self::k('bank_account_name'));
        return $name !== '' && $number !== '' && $holder !== '' ? ['name' => $name, 'number' => $number, 'holder' => $holder] : null;
    }

    public static function mode(): string
    {
        $m = Setting::get(self::k('billplz_mode'), 'sandbox');
        return isset(self::MODES[$m]) ? $m : 'sandbox';
    }

    /**
     * Saves the details. A secret field left blank keeps the stored value.
     * @param array<string,string> $in
     * @return array<string,string> errors (empty when saved)
     */
    public static function save(array $in, int $actorId): array
    {
        $errors = [];
        $mode = (string) ($in['billplz_mode'] ?? 'sandbox');
        if (!isset(self::MODES[$mode])) {
            $errors['billplz_mode'] = 'Choose sandbox or production.';
        }
        $col = trim((string) ($in['billplz_collection_id'] ?? ''));
        if ($col !== '' && !preg_match('/^[A-Za-z0-9_\-]{3,64}$/', $col)) {
            $errors['billplz_collection_id'] = 'The collection ID has letters, numbers, dashes or underscores only.';
        }
        $bank = ['bank_name' => 60, 'bank_account_number' => 30, 'bank_account_name' => 120];
        $bankValues = [];
        foreach ($bank as $field => $max) {
            $v = trim((string) ($in[$field] ?? ''));
            if (mb_strlen($v) > $max) {
                $errors[$field] = "Keep this under {$max} characters.";
            }
            $bankValues[$field] = $v;
        }
        if ($errors !== []) {
            return $errors;
        }
        Setting::set(self::k('billplz_mode'), $mode);
        Setting::set(self::k('billplz_collection_id'), $col);
        foreach ($bankValues as $field => $v) {
            Setting::set(self::k($field), $v);
        }
        foreach (self::SECRETS as $name) {
            $new = trim((string) ($in[$name] ?? ''));
            if ($new !== '') {
                Setting::set(self::k($name), SecretBox::encrypt($new));
            }
        }
        AuditService::log($actorId, 'platform.settings', 'payment details updated (secrets not logged)');
        return [];
    }

    private static function secret(string $name): ?string
    {
        $stored = Setting::get(self::k($name));
        return $stored === '' ? null : SecretBox::decrypt($stored);
    }
}
