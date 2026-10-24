<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class AuditService
{
    /** Never pass secrets in $detail: field names and facts only. */
    public static function log(?int $actorId, string $action, ?string $detail = null): void
    {
        Database::query(
            'INSERT INTO audit_logs (actor_id, action, detail, ip) VALUES (?, ?, ?, ?)',
            [$actorId, $action, $detail === null ? null : mb_substr($detail, 0, 255), $_SERVER['REMOTE_ADDR'] ?? null]
        );
    }

    /** @return array<int,array{action:string,detail:?string,created_at:string,actor:?string}> */
    public static function recent(string $actionPrefix, int $limit = 10): array
    {
        return Database::query(
            'SELECT a.action, a.detail, a.created_at, u.name AS actor FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id
             WHERE a.action LIKE ? ORDER BY a.id DESC LIMIT ' . (int) $limit,
            [addcslashes($actionPrefix, '%_\\') . '%']
        )->fetchAll();
    }
}
