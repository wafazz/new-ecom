<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Authenticated encryption (libsodium secretbox) for secrets kept in the database. The key is derived
 * from APP_KEY, so a database leak alone does not expose them. Changing APP_KEY makes old values
 * undecryptable (decrypt() returns null) and they must be re-entered.
 */
final class SecretBox
{
    private const PREFIX = 'enc:v1:';

    private static function key(): string
    {
        $k = (string) env('APP_KEY', '');
        if ($k === '') {
            throw new \RuntimeException('APP_KEY must be set to store secrets.');
        }
        return sodium_crypto_generichash('secretbox-v1|' . $k, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    /** @return string|null null when the value is not ours, was tampered with, or the key changed */
    public static function decrypt(string $stored): ?string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::key()
        );
        return $plain === false ? null : $plain;
    }
}
