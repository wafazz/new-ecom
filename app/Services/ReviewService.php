<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Product reviews. Only a member with a completed order containing the product may review it, once per product.
 * Reviews are shown straight away; staff can hide or delete them.
 */
final class ReviewService
{
    public const MIN_BODY = 10;
    public const MAX_BODY = 1000;
    public const MAX_TITLE = 120;

    /** @return array{errors: array<string,string>, data: array{rating:int, title:?string, body:string}} */
    public static function validate(array $in): array
    {
        $errors = [];
        $rating = filter_var($in['rating'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        if ($rating === false) {
            $errors['rating'] = 'Choose a rating from 1 to 5 stars.';
        }

        $title = trim((string) ($in['title'] ?? ''));
        if (mb_strlen($title) > self::MAX_TITLE) {
            $errors['title'] = 'The title can be at most ' . self::MAX_TITLE . ' characters.';
        }

        $body = trim((string) ($in['body'] ?? ''));
        $len = mb_strlen($body);
        if ($len < self::MIN_BODY) {
            $errors['body'] = 'Tell other shoppers a little more (at least ' . self::MIN_BODY . ' characters).';
        } elseif ($len > self::MAX_BODY) {
            $errors['body'] = 'Keep the review under ' . self::MAX_BODY . ' characters.';
        }

        return [
            'errors' => $errors,
            'data' => ['rating' => (int) $rating, 'title' => $title !== '' ? $title : null, 'body' => $body],
        ];
    }

    /** The completed order that makes this member a buyer of the product, or null. */
    public static function eligibleOrderId(int $userId, int $productId): ?int
    {
        $id = Database::query(
            "SELECT o.id FROM orders o JOIN order_items oi ON oi.order_id = o.id
             WHERE o.user_id = ? AND oi.product_id = ? AND o.status = 'completed'
             ORDER BY o.id DESC LIMIT 1",
            [$userId, $productId]
        )->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** What a member can do on a product: 'reviewed', 'eligible', or 'not_buyer'. */
    public static function stateFor(int $userId, int $productId): string
    {
        $done = (int) Database::query('SELECT COUNT(*) FROM product_reviews WHERE user_id = ? AND product_id = ?', [$userId, $productId])->fetchColumn();
        if ($done > 0) {
            return 'reviewed';
        }
        return self::eligibleOrderId($userId, $productId) !== null ? 'eligible' : 'not_buyer';
    }

    /** @return int|string the new review id, or the reason it was refused */
    public static function create(int $userId, int $productId, array $data): int|string
    {
        $orderId = self::eligibleOrderId($userId, $productId);
        if ($orderId === null) {
            return 'Only customers with a completed order for this product can review it.';
        }
        try {
            Database::query(
                "INSERT INTO product_reviews (product_id, user_id, order_id, rating, title, body, status) VALUES (?, ?, ?, ?, ?, ?, 'approved')",
                [$productId, $userId, $orderId, $data['rating'], $data['title'], $data['body']]
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'You have already reviewed this product.';
            }
            throw $e;
        }
        return (int) Database::connection()->lastInsertId();
    }

    /** Average (one decimal), count, and how many of each star, over approved reviews. */
    public static function summary(int $productId): array
    {
        $counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach (Database::query("SELECT rating, COUNT(*) n FROM product_reviews WHERE product_id = ? AND status = 'approved' GROUP BY rating", [$productId])->fetchAll() as $r) {
            $counts[(int) $r['rating']] = (int) $r['n'];
        }
        $count = array_sum($counts);
        $avg = $count > 0 ? round(array_sum(array_map(fn($s, $n) => $s * $n, array_keys($counts), $counts)) / $count, 1) : null;
        return ['avg' => $avg, 'count' => $count, 'counts' => $counts];
    }

    /** Approved reviews for a product, newest first, with the reviewer's name shortened for the public. */
    public static function list(int $productId, int $limit = 30): array
    {
        $rows = Database::query(
            "SELECT r.id, r.rating, r.title, r.body, r.created_at, u.name AS reviewer, (r.order_id IS NOT NULL) AS verified
             FROM product_reviews r JOIN users u ON u.id = r.user_id
             WHERE r.product_id = ? AND r.status = 'approved' ORDER BY r.created_at DESC, r.id DESC LIMIT " . (int) $limit,
            [$productId]
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['reviewer'] = self::publicName((string) $r['reviewer']);
        }
        unset($r);
        return $rows;
    }

    /** "Nur Aisyah" becomes "Nur A.": first name plus an initial, never the full name. */
    public static function publicName(string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: [], fn($p) => $p !== ''));
        $first = $parts[0] ?? 'Shopper';
        $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) . '.' : '';
        return trim($first . ' ' . $last);
    }

    /** Every review for staff, newest first, with the product name. */
    public static function all(int $limit = 200): array
    {
        return Database::query(
            "SELECT r.*, p.name AS product_name, u.name AS reviewer, (r.order_id IS NOT NULL) AS verified
             FROM product_reviews r JOIN products p ON p.id = r.product_id JOIN users u ON u.id = r.user_id
             ORDER BY r.created_at DESC, r.id DESC LIMIT " . (int) $limit
        )->fetchAll();
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['approved', 'hidden'], true)) {
            return;
        }
        Database::query('UPDATE product_reviews SET status = ? WHERE id = ?', [$status, $id]);
    }

    public static function delete(int $id): void
    {
        Database::query('DELETE FROM product_reviews WHERE id = ?', [$id]);
    }
}
