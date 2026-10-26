<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\SecretBox;

/**
 * Digital products. Nothing here is public except the download link: files live in storage/digital (outside the
 * web root), a grant is created when the order is paid, and every download is counted in the same statement that
 * checks the limit, so two clicks at once cannot get past it.
 */
final class DigitalService
{
    public const DELIVERIES = ['file' => 'Uploaded file', 'link' => 'External link', 'keys' => 'License keys'];
    public const MAX_FILE_BYTES = 100 * 1024 * 1024;
    public const MAX_KEYS_PER_SAVE = 2000;
    public const DEFAULT_EXPIRY_DAYS = 30;
    public const DEFAULT_MAX_DOWNLOADS = 5;
    /** Stands in for "no stock limit" for files and links, so the shop's stock checks keep one code path. */
    public const UNLIMITED = 1000000;

    public static function dir(): string
    {
        return BASE_PATH . '/storage/digital';
    }

    public static function isDigital(array $product): bool
    {
        return ($product['product_type'] ?? 'physical') === 'digital';
    }

    /** How many can be sold right now: the free license keys, or unlimited for files and links. */
    public static function stock(array $product): int
    {
        if (($product['digital_delivery'] ?? null) !== 'keys') {
            return self::UNLIMITED;
        }
        return self::freeKeys((int) $product['id']);
    }

    public static function freeKeys(int $productId): int
    {
        return (int) Database::query('SELECT COUNT(*) FROM product_license_keys WHERE product_id = ? AND order_item_id IS NULL', [$productId])->fetchColumn();
    }

    /** Keys already sold to one order item (a paid order can be checked more than once). */
    public static function keysSold(int $productId, int $orderItemId): int
    {
        return (int) Database::query('SELECT COUNT(*) FROM product_license_keys WHERE product_id = ? AND order_item_id = ?', [$productId, $orderItemId])->fetchColumn();
    }

    /**
     * Store an uploaded file under a random name. Returns the stored name and the name the buyer will see.
     * @throws \RuntimeException with a message for the admin
     */
    public static function storeFile(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('The file is too large. The limit is ' . (self::MAX_FILE_BYTES / 1048576) . ' MB.');
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new \RuntimeException('The file could not be uploaded. Please try again.');
        }
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('The digital files folder cannot be created on the server.');
        }
        $stored = bin2hex(random_bytes(16));
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $stored)) {
            throw new \RuntimeException('The digital files folder cannot be written to on the server.');
        }
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', basename((string) $file['name'])) ?? '';
        return ['path' => $stored, 'name' => mb_substr($name !== '' ? $name : 'download', 0, 190)];
    }

    public static function deleteFile(string $stored): void
    {
        $file = self::dir() . '/' . basename($stored);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** One link per line, blanks dropped, duplicates in the same save dropped. @return string[] */
    public static function parseKeys(string $text): array
    {
        $keys = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $k = trim($line);
            if ($k !== '' && !in_array($k, $keys, true)) {
                $keys[] = mb_substr($k, 0, 500);
            }
        }
        return array_slice($keys, 0, self::MAX_KEYS_PER_SAVE);
    }

    /** Add keys to the pool. They are stored encrypted and never shown again in the admin pages. */
    public static function addKeys(int $productId, array $keys): int
    {
        foreach ($keys as $k) {
            Database::query('INSERT INTO product_license_keys (product_id, key_enc) VALUES (?, ?)', [$productId, SecretBox::encrypt($k)]);
        }
        return count($keys);
    }

    /** A link is only sent to the buyer when it is a plain web address. */
    public static function validLink(string $link): bool
    {
        if (strlen($link) > 500 || filter_var($link, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        return in_array(strtolower((string) parse_url($link, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /**
     * Called once an order is paid (and not cancelled). Creates the download grants for file and link items and
     * assigns license keys from the pool. Safe to call again: items already granted are skipped.
     */
    public static function grantForOrder(int $orderId): void
    {
        $order = Database::query('SELECT status, payment_status FROM orders WHERE id = ?', [$orderId])->fetch();
        if (!$order || $order['payment_status'] !== 'paid' || $order['status'] === 'cancelled') {
            return;
        }
        $items = Database::query(
            'SELECT id, product_id, product_name, qty, digital_delivery FROM order_items WHERE order_id = ? AND digital_delivery IS NOT NULL ORDER BY id',
            [$orderId]
        )->fetchAll();

        foreach ($items as $it) {
            $productId = $it['product_id'] !== null ? (int) $it['product_id'] : null;
            if ($productId === null) {
                OrderService::note($orderId, 'digital', "{$it['product_name']}: the product was removed, so its download could not be prepared. Contact the buyer.");
                continue;
            }
            if ($it['digital_delivery'] === 'keys') {
                self::assignKeys($orderId, (int) $it['id'], $productId, (int) $it['qty'], $it['product_name']);
                continue;
            }
            self::grantLink($orderId, (int) $it['id'], $productId);
        }
    }

    private static function assignKeys(int $orderId, int $itemId, int $productId, int $qty, string $name): void
    {
        $need = $qty - self::keysSold($productId, $itemId);
        if ($need <= 0) {
            return;
        }
        // Lock the free keys for this product, then take as many as are needed. Keys are never handed to two items.
        Database::query('SELECT id FROM products WHERE id = ? FOR UPDATE', [$productId]);
        $ids = Database::query('SELECT id FROM product_license_keys WHERE product_id = ? AND order_item_id IS NULL ORDER BY id LIMIT ' . (int) $need . ' FOR UPDATE', [$productId])->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $keyId) {
            Database::query('UPDATE product_license_keys SET order_item_id = ?, assigned_at = NOW() WHERE id = ?', [$itemId, (int) $keyId]);
        }
        if (count($ids) < $need) {
            OrderService::note($orderId, 'digital', "{$name}: " . ($need - count($ids)) . ' license key(s) missing. Add keys to the product, then re-check this order.');
        }
    }

    private static function grantLink(int $orderId, int $itemId, int $productId): void
    {
        if (Database::query('SELECT COUNT(*) FROM digital_grants WHERE order_item_id = ?', [$itemId])->fetchColumn() > 0) {
            return;
        }
        $p = Database::query('SELECT digital_delivery, digital_file_path, digital_file_name, digital_link, digital_expiry_days, digital_max_downloads FROM products WHERE id = ?', [$productId])->fetch();
        if (!$p || !in_array($p['digital_delivery'], ['file', 'link'], true)) {
            return; // the product was changed to another delivery after the sale: nothing to send
        }
        $token = bin2hex(random_bytes(24));
        $days = (int) ($p['digital_expiry_days'] ?: self::DEFAULT_EXPIRY_DAYS);
        $max = (int) ($p['digital_max_downloads'] ?: self::DEFAULT_MAX_DOWNLOADS);
        try {
            Database::query(
                'INSERT INTO digital_grants (order_id, order_item_id, token_hash, token_enc, delivery, file_path, file_name, link, expires_at, max_downloads)
                 VALUES (?,?,?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL ' . $days . ' DAY),?)',
                [
                    $orderId, $itemId, hash('sha256', $token), SecretBox::encrypt($token), $p['digital_delivery'],
                    $p['digital_delivery'] === 'file' ? $p['digital_file_path'] : null,
                    $p['digital_delivery'] === 'file' ? $p['digital_file_name'] : null,
                    $p['digital_delivery'] === 'link' ? $p['digital_link'] : null,
                    $max,
                ]
            );
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e; // a duplicate from a parallel call is fine; anything else is a real error
            }
        }
    }

    /** The grants of one order, with the link the buyer can use (for the order page). */
    public static function forOrder(int $orderId): array
    {
        $rows = Database::query(
            'SELECT g.id, g.order_item_id, g.token_enc, g.delivery, g.file_name, g.link, g.expires_at, g.max_downloads, g.downloads, oi.product_name
             FROM digital_grants g JOIN order_items oi ON oi.id = g.order_item_id
             WHERE g.order_id = ? ORDER BY g.id',
            [$orderId]
        )->fetchAll();
        foreach ($rows as &$r) {
            $token = SecretBox::decrypt((string) $r['token_enc']);
            $r['url'] = $token === null ? null : url('/download/' . $token);
        }
        return $rows;
    }

    /** License keys sold to one order item, decrypted for the order page. @return string[] */
    public static function keysFor(int $orderItemId): array
    {
        $keys = [];
        foreach (Database::query('SELECT key_enc FROM product_license_keys WHERE order_item_id = ? ORDER BY id', [$orderItemId])->fetchAll(\PDO::FETCH_COLUMN) as $enc) {
            $plain = SecretBox::decrypt((string) $enc);
            if ($plain !== null) {
                $keys[] = $plain;
            }
        }
        return $keys;
    }

    /**
     * Check a download link and count it. Returns the grant to send, or the reason it is refused.
     * @return array{ok: bool, code: int, message: string, grant: ?array}
     */
    public static function claim(string $token): array
    {
        $g = Database::query(
            'SELECT g.*, o.status, o.payment_status, (g.expires_at < NOW()) AS expired
             FROM digital_grants g JOIN orders o ON o.id = g.order_id WHERE g.token_hash = ?',
            [hash('sha256', $token)]
        )->fetch();
        if (!$g) {
            return ['ok' => false, 'code' => 404, 'message' => 'This download link is not valid.', 'grant' => null];
        }
        if ($g['payment_status'] !== 'paid' || in_array($g['status'], ['cancelled', 'returned'], true)) {
            return ['ok' => false, 'code' => 403, 'message' => 'This download is not available for this order.', 'grant' => null];
        }
        if ((int) $g['expired'] === 1) {
            return ['ok' => false, 'code' => 410, 'message' => 'This download link has expired.', 'grant' => null];
        }
        if ($g['delivery'] === 'file' && !is_file(self::dir() . '/' . basename((string) $g['file_path']))) {
            return ['ok' => false, 'code' => 404, 'message' => 'This file is no longer available. Please contact the shop.', 'grant' => null];
        }
        // The check and the count happen in one statement, so two clicks at the same time cannot both get past the limit.
        $stmt = Database::query(
            'UPDATE digital_grants SET downloads = downloads + 1 WHERE id = ? AND downloads < max_downloads AND expires_at > NOW()',
            [(int) $g['id']]
        );
        if ($stmt->rowCount() !== 1) {
            return ['ok' => false, 'code' => 410, 'message' => 'This download has reached its limit.', 'grant' => null];
        }
        return ['ok' => true, 'code' => 200, 'message' => '', 'grant' => $g];
    }

    /** Send the file (attachment, never inline) or redirect to the link. Never returns. */
    public static function send(array $grant): never
    {
        if ($grant['delivery'] === 'link') {
            if (!self::validLink((string) $grant['link'])) {
                \App\Core\Response::abort(404, 'This link is not available.');
            }
            header('Cache-Control: no-store');
            header('Referrer-Policy: no-referrer');
            header('Location: ' . $grant['link'], true, 302);
            exit;
        }
        $file = self::dir() . '/' . basename((string) $grant['file_path']);
        if (!is_file($file)) {
            \App\Core\Response::abort(404, 'This file is no longer available. Please contact the shop.');
        }
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string) $grant['file_name']) ?: 'download';
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($file));
        header("Content-Disposition: attachment; filename=\"{$name}\"; filename*=UTF-8''" . rawurlencode((string) $grant['file_name']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        readfile($file);
        exit;
    }
}
