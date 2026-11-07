<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Bearer tokens that let CodexLure read this shop's sales totals. Only a SHA-256 hash is stored;
 * the plain token is returned once, at creation, and can never be read back.
 */
final class SalesApiTokenService
{
    private const PREFIX = 'shop_sales_';

    /** @return array{id: int, token: string} The plain token is in this result only. */
    public static function create(string $label, ?int $adminId): array
    {
        $plain = self::PREFIX . bin2hex(random_bytes(24));
        Database::query(
            'INSERT INTO sales_api_tokens (label, token_hash, token_last4, created_by) VALUES (?, ?, ?, ?)',
            [$label, hash('sha256', $plain), substr($plain, -4), $adminId]
        );

        return ['id' => (int) Database::connection()->lastInsertId(), 'token' => $plain];
    }

    /** @return array<int, array<string, mixed>> Newest first. */
    public static function list(): array
    {
        return Database::query(
            'SELECT id, label, token_last4, created_at, last_used_at, revoked_at FROM sales_api_tokens ORDER BY id DESC LIMIT 100'
        )->fetchAll();
    }

    public static function revoke(int $id): bool
    {
        return Database::query(
            'UPDATE sales_api_tokens SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL',
            [$id]
        )->rowCount() === 1;
    }

    /** True when the token is one of ours and not revoked. Records the time of use. */
    public static function authorise(string $token): bool
    {
        $row = Database::query(
            'SELECT id FROM sales_api_tokens WHERE token_hash = ? AND revoked_at IS NULL LIMIT 1',
            [hash('sha256', $token)]
        )->fetch();
        if (!$row) {
            return false;
        }
        Database::query('UPDATE sales_api_tokens SET last_used_at = NOW() WHERE id = ?', [(int) $row['id']]);

        return true;
    }

    /** The token from "Authorization: Bearer ...", or null. */
    public static function bearer(): ?string
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

        return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : null;
    }
}
