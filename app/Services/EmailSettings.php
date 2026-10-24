<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\SecretBox;
use App\Models\Setting;

/** Email (Resend) settings: on/off, API key (encrypted), sender, and the two automatic email templates. */
final class EmailSettings
{
    public const KINDS = ['verify', 'order', 'reset'];

    /** Placeholders per automatic email. */
    public const VARIABLES = [
        'verify' => ['{name}', '{verify_url}', '{shop}'],
        'order' => ['{name}', '{order_no}', '{total}', '{items}', '{payment}', '{track_url}', '{shop}'],
        'reset' => ['{name}', '{reset_url}', '{shop}'],
    ];

    /** @return array<string,array{subject:string, body:string}> */
    public static function defaults(): array
    {
        return [
            'verify' => [
                'subject' => 'Sahkan alamat emel anda',
                'body' => "Hai {name},\n\nSila sahkan alamat emel anda dengan klik pautan ini:\n{verify_url}\n\nPautan ini sah selama 48 jam.\n\n{shop}",
            ],
            'order' => [
                'subject' => 'Pesanan {order_no} telah diterima',
                'body' => "Hai {name},\n\nTerima kasih! Pesanan {order_no} telah diterima.\n\n{items}\n\nJumlah: RM{total}\nBayaran: {payment}\n\nJejak pesanan anda: {track_url}\n\n{shop}",
            ],
            'reset' => [
                'subject' => 'Tetapkan semula kata laluan anda',
                'body' => "Hai {name},\n\nKami terima permintaan untuk menetapkan semula kata laluan anda. Klik pautan ini:\n{reset_url}\n\nPautan ini sah selama 60 minit dan hanya boleh digunakan sekali.\n\nJika anda tidak membuat permintaan ini, abaikan emel ini. Kata laluan anda tidak akan berubah.\n\n{shop}",
            ],
        ];
    }

    public static function enabled(): bool
    {
        return Setting::get('email.enabled', '0') === '1';
    }

    public static function apiKey(): ?string
    {
        $stored = Setting::get('email.key', '');
        return $stored === '' ? null : SecretBox::decrypt($stored);
    }

    public static function fromName(): string
    {
        return Setting::get('email.from_name', (string) config('app.name'));
    }

    public static function fromEmail(): string
    {
        return Setting::get('email.from_email', '');
    }

    public static function replyTo(): string
    {
        return Setting::get('email.reply_to', '');
    }

    /** @return array{on:bool, subject:string, body:string} */
    public static function template(string $kind): array
    {
        $d = self::defaults()[$kind];
        return [
            'on' => Setting::get("email.on.{$kind}", '1') === '1',
            'subject' => Setting::get("email.subject.{$kind}", $d['subject']),
            'body' => Setting::get("email.body.{$kind}", $d['body']),
        ];
    }

    /** What the settings page needs; never the key itself. */
    public static function overview(): array
    {
        $key = self::apiKey();
        return [
            'enabled' => self::enabled(),
            'key_set' => $key !== null,
            'key_hint' => $key !== null ? '…' . substr($key, -4) : null,
            'from_name' => self::fromName(),
            'from_email' => self::fromEmail(),
            'reply_to' => self::replyTo(),
            'templates' => [
                'verify' => self::template('verify'),
                'order' => self::template('order'),
                'reset' => self::template('reset'),
            ],
        ];
    }

    /**
     * @param array{enabled:string, key:string, from_name:string, from_email:string, reply_to:string, tpl:array<string,array{on?:string,subject?:string,body?:string}>} $in
     * @return string[] error messages (empty when saved)
     */
    public static function save(array $in, int $actorId): array
    {
        $errors = [];
        $key = trim((string) ($in['key'] ?? ''));
        $fromEmail = trim((string) ($in['from_email'] ?? ''));
        $replyTo = trim((string) ($in['reply_to'] ?? ''));
        $enabled = ($in['enabled'] ?? '') === '1';

        if ($key !== '' && (!str_starts_with($key, 're_') || preg_match('/\s/', $key) || strlen($key) > 200)) {
            $errors[] = 'The API key looks wrong. Resend keys start with re_. Copy it again from resend.com/api-keys.';
        }
        if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'The sender email address is not valid.';
        }
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'The reply-to email address is not valid.';
        }
        if ($enabled && ($key === '' && self::apiKey() === null)) {
            $errors[] = 'Enter the Resend API key before turning email on.';
        }
        if ($enabled && ($fromEmail === '' && self::fromEmail() === '')) {
            $errors[] = 'Enter the sender email address (a domain verified in Resend) before turning email on.';
        }
        foreach (self::KINDS as $kind) {
            $subject = trim((string) ($in['tpl'][$kind]['subject'] ?? ''));
            $body = trim((string) ($in['tpl'][$kind]['body'] ?? ''));
            if ($subject === '' || $body === '') {
                $errors[] = ucfirst($kind) . ' email needs a subject and a message.';
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        Setting::set('email.enabled', $enabled ? '1' : '0');
        if ($key !== '') {
            Setting::set('email.key', SecretBox::encrypt($key));
        }
        Setting::set('email.from_name', trim((string) ($in['from_name'] ?? '')) ?: (string) config('app.name'));
        if ($fromEmail !== '') {
            Setting::set('email.from_email', $fromEmail);
        }
        Setting::set('email.reply_to', $replyTo);
        foreach (self::KINDS as $kind) {
            Setting::set("email.on.{$kind}", ($in['tpl'][$kind]['on'] ?? '') === '1' ? '1' : '0');
            Setting::set("email.subject.{$kind}", trim((string) $in['tpl'][$kind]['subject']));
            Setting::set("email.body.{$kind}", trim((string) $in['tpl'][$kind]['body']));
        }
        AuditService::log($actorId, 'email.update', 'Email settings saved' . ($key !== '' ? ' (key changed)' : ''));
        return [];
    }
}
