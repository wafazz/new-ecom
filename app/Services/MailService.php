<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Sends email through Resend (POST /emails, POST /emails/batch up to 100, Bearer key).
 * A failed email is logged; it never stops the order or sign-up that triggered it.
 */
final class MailService
{
    public const BASE = 'https://api.resend.com';
    public const BATCH_SIZE = 100;

    /** Tests replace the network call: fn(string $method, string $path, string $key, ?string $body, array $headers): array. */
    private static ?\Closure $transport = null;

    public static function setTransport(?\Closure $transport): void
    {
        self::$transport = $transport;
    }

    /** @return array{ok:bool, message:string, id:?string} */
    public static function send(string $to, string $subject, string $text, string $kind, ?int $orderId = null): array
    {
        $key = EmailSettings::apiKey();
        if (!EmailSettings::enabled() || $key === null || EmailSettings::fromEmail() === '') {
            return ['ok' => false, 'message' => 'Email is not set up.', 'id' => null];
        }
        $body = self::message($to, $subject, $text);
        $r = self::http('POST', '/emails', $key, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), []);
        $json = is_array($r['json']) ? $r['json'] : [];
        $result = isset($json['id']) && is_string($json['id']) && $r['code'] === 200
            ? ['ok' => true, 'message' => 'sent', 'id' => $json['id']]
            : ['ok' => false, 'message' => self::errorText($r), 'id' => null];
        self::log($kind, $to, $subject, $result, $orderId);
        return $result;
    }

    /**
     * Up to BATCH_SIZE messages in one request. Each item: ['to' => string, 'subject' => string, 'text' => string].
     * @param array<int,array{to:string,subject:string,text:string}> $items
     * @return array<int,array{ok:bool, message:string, id:?string}> one result per item, same order
     */
    public static function batch(array $items, string $kind, string $idempotencyKey): array
    {
        $key = EmailSettings::apiKey();
        if (!EmailSettings::enabled() || $key === null || EmailSettings::fromEmail() === '' || $items === []) {
            return array_fill(0, count($items), ['ok' => false, 'message' => 'Email is not set up.', 'id' => null]);
        }
        $payload = array_map(fn($it) => self::message($it['to'], $it['subject'], $it['text'], $it['headers'] ?? []), array_slice($items, 0, self::BATCH_SIZE));
        $r = self::http('POST', '/emails/batch', $key, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ['Idempotency-Key: ' . substr($idempotencyKey, 0, 256)]);
        $data = is_array($r['json']['data'] ?? null) ? $r['json']['data'] : [];
        $out = [];
        foreach ($items as $i => $it) {
            $id = $r['code'] === 200 && isset($data[$i]['id']) ? (string) $data[$i]['id'] : null;
            $out[$i] = $id !== null
                ? ['ok' => true, 'message' => 'sent', 'id' => $id]
                : ['ok' => false, 'message' => self::errorText($r), 'id' => null];
            self::log($kind, $it['to'], $it['subject'], $out[$i], null);
        }
        return $out;
    }

    /** "Shop <sender@domain>" or just the address. */
    public static function from(): string
    {
        $name = str_replace(['<', '>', '"'], '', EmailSettings::fromName());
        return $name === '' ? EmailSettings::fromEmail() : $name . ' <' . EmailSettings::fromEmail() . '>';
    }

    /** @param array<string,string> $headers extra mail headers (for example List-Unsubscribe) @return array<string,mixed> */
    private static function message(string $to, string $subject, string $text, array $headers = []): array
    {
        $m = ['from' => self::from(), 'to' => [$to], 'subject' => $subject, 'text' => $text, 'html' => self::html($text)];
        if ($headers !== []) {
            $m['headers'] = $headers;
        }
        $replyTo = EmailSettings::replyTo();
        if ($replyTo !== '') {
            $m['reply_to'] = $replyTo;
        }
        return $m;
    }

    /** The same text as HTML, escaped: line breaks kept, nothing from the text is treated as markup. */
    public static function html(string $text): string
    {
        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#111">'
            . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
            . '</div>';
    }

    /** @param array{ok:bool, message:string, id:?string} $r */
    public static function log(string $kind, string $to, string $subject, array $r, ?int $orderId): void
    {
        Database::query(
            'INSERT INTO email_log (kind, to_email, subject, ok, resend_id, error, order_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$kind, mb_substr($to, 0, 190), mb_substr($subject, 0, 255), $r['ok'] ? 1 : 0, $r['id'], $r['ok'] ? null : mb_substr($r['message'], 0, 255), $orderId]
        );
    }

    /** @param array{code:int, json:mixed} $r */
    private static function errorText(array $r): string
    {
        $json = is_array($r['json']) ? $r['json'] : [];
        if ($r['code'] === 0) {
            return 'Network problem';
        }
        $msg = is_string($json['message'] ?? null) ? $json['message'] : (is_string($json['name'] ?? null) ? $json['name'] : "HTTP {$r['code']}");
        return mb_substr($msg, 0, 255);
    }

    /** @return array{code:int, json:mixed} */
    private static function http(string $method, string $path, string $key, ?string $body, array $extraHeaders): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($method, $path, $key, $body, $extraHeaders);
        }
        $ch = curl_init(self::BASE . $path);
        $headers = array_merge(['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $key], $extraHeaders);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body ?? '',
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $raw = curl_exec($ch);
        $code = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['code' => $code, 'json' => is_string($raw) ? json_decode($raw, true) : null];
    }
}
