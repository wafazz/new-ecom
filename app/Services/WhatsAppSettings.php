<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\SecretBox;
use App\Models\Setting;

/** WhatsApp (onsend.io) settings: on/off, the device token (encrypted), and one message template per order status. */
final class WhatsAppSettings
{
    /** Order status => label shown to HQ. */
    public const STATUSES = [
        'pending' => 'Order placed',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'returned' => 'Returned',
    ];

    /** Placeholders a template may use. */
    public const VARIABLES = ['{name}', '{order_no}', '{total}', '{status}', '{tracking}', '{shop}', '{ordered_item}'];

    /** @return array<string,string> */
    public static function defaults(): array
    {
        return [
            'pending' => "Hai {name}, terima kasih! Pesanan {order_no} telah diterima. Jumlah RM{total}. Kami akan maklumkan bila pesanan dihantar.",
            'processing' => 'Hai {name}, pesanan {order_no} sedang disediakan. Terima kasih kerana bersabar.',
            'shipped' => 'Hai {name}, pesanan {order_no} telah dihantar. No. tracking: {tracking}.',
            'completed' => 'Hai {name}, pesanan {order_no} telah selesai. Terima kasih kerana membeli di {shop}.',
            'cancelled' => 'Hai {name}, pesanan {order_no} telah dibatalkan. Jika ada pertanyaan, sila balas mesej ini.',
            'returned' => 'Hai {name}, pesanan {order_no} telah dikembalikan. Kami akan hubungi anda jika ada tindakan lanjut.',
        ];
    }

    public static function enabled(): bool
    {
        return Setting::get('wa.enabled', '0') === '1';
    }

    public static function token(): ?string
    {
        $stored = Setting::get('wa.token', '');
        return $stored === '' ? null : SecretBox::decrypt($stored);
    }

    /** @return array{on:bool, text:string} */
    public static function template(string $status): array
    {
        $defaults = self::defaults();
        return [
            'on' => Setting::get("wa.on.{$status}", '1') === '1',
            'text' => Setting::get("wa.text.{$status}", $defaults[$status] ?? ''),
        ];
    }

    /** What the settings page needs; never the token itself. */
    public static function overview(): array
    {
        $token = self::token();
        $templates = [];
        foreach (array_keys(self::STATUSES) as $s) {
            $templates[$s] = self::template($s);
        }
        return [
            'enabled' => self::enabled(),
            'token_set' => $token !== null,
            'token_hint' => $token !== null ? '…' . substr($token, -4) : null,
            'templates' => $templates,
        ];
    }

    /**
     * @param array{enabled:string, token:string, tpl:array<string,array{on?:string,text?:string}>} $in
     * @return string[] error messages (empty when saved)
     */
    public static function save(array $in, int $actorId): array
    {
        $errors = [];
        $token = trim((string) ($in['token'] ?? ''));
        $enabled = ($in['enabled'] ?? '') === '1';
        if ($token !== '' && (strlen($token) > 500 || preg_match('/\s/', $token))) {
            $errors[] = 'The API token looks wrong. Copy it again from the onsend.io Devices page.';
        }
        if ($enabled && $token === '' && self::token() === null) {
            $errors[] = 'Enter the API token before turning WhatsApp on.';
        }
        foreach (array_keys(self::STATUSES) as $s) {
            $text = trim((string) ($in['tpl'][$s]['text'] ?? ''));
            if ($text === '') {
                $errors[] = self::STATUSES[$s] . ' message cannot be empty. Untick "On" to stop it instead.';
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        Setting::set('wa.enabled', $enabled ? '1' : '0');
        if ($token !== '') {
            Setting::set('wa.token', SecretBox::encrypt($token));
        }
        foreach (array_keys(self::STATUSES) as $s) {
            Setting::set("wa.on.{$s}", ($in['tpl'][$s]['on'] ?? '') === '1' ? '1' : '0');
            Setting::set("wa.text.{$s}", trim((string) ($in['tpl'][$s]['text'] ?? '')));
        }
        AuditService::log($actorId, 'whatsapp.update', 'WhatsApp settings saved' . ($token !== '' ? ' (token changed)' : ''));
        return [];
    }
}
