<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Every email the shop sends, searched by type, result, address or subject, and date range. */
final class EmailLog
{
    public const PER_PAGE = 50;

    /** The types of email, for the filter and the table. */
    public const KINDS = [
        'verify' => 'Sign-up confirmation',
        'reset' => 'Password reset',
        'order' => 'Order created',
        'campaign' => 'Announcement',
        'voucher' => 'Voucher code',
        'test' => 'Test email',
    ];

    /**
     * @param array{kind?:string, status?:string, q?:string, from?:string, to?:string} $f
     * Unknown types, statuses and invalid dates are ignored, so a bad link shows everything rather than failing.
     * @return array{rows:array<int,array>, total:int, page:int, pages:int}
     */
    public static function search(array $f, int $page): array
    {
        $where = [];
        $params = [];
        if (isset(self::KINDS[$f['kind'] ?? ''])) {
            $where[] = 'kind = ?';
            $params[] = $f['kind'];
        }
        if (($f['status'] ?? '') === 'sent') {
            $where[] = 'ok = 1';
        } elseif (($f['status'] ?? '') === 'failed') {
            $where[] = 'ok = 0';
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
            $where[] = '(to_email LIKE ? OR subject LIKE ?)';
            array_push($params, $like, $like);
        }
        if (self::isDate((string) ($f['from'] ?? ''))) {
            $where[] = 'created_at >= ?';
            $params[] = $f['from'] . ' 00:00:00';
        }
        if (self::isDate((string) ($f['to'] ?? ''))) {
            $where[] = 'created_at < ?';
            $params[] = date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00';
        }
        $sql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) Database::query('SELECT COUNT(*) FROM email_log' . $sql, $params)->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = Database::query(
            'SELECT * FROM email_log' . $sql . ' ORDER BY id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params
        )->fetchAll();
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    private static function isDate(string $d): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        return $parsed !== false && $parsed->format('Y-m-d') === $d;
    }
}
