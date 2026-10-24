<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\Product;

/**
 * Cart owner = logged-in member (user_id) or an anonymous browser (cookie token, stored hashed).
 * Prices are never stored here: they are always read live so a price change shows immediately.
 */
final class CartService
{
    public const MAX_QTY = 99;
    private const COOKIE = 'cart_token';
    private const GUEST_DAYS = 7;

    private static function memberId(): ?int
    {
        $u = Auth::user();
        return $u !== null && $u['role'] === 'member' ? (int) $u['id'] : null;
    }

    private static function token(): ?string
    {
        $t = $_COOKIE[self::COOKIE] ?? null;
        return is_string($t) && preg_match('/^[a-f0-9]{64}$/', $t) ? $t : null;
    }

    private static function setCookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => str_starts_with((string) env('APP_URL', ''), 'https'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** Current cart id, optionally creating it. */
    public static function id(bool $create = false): ?int
    {
        if (($uid = self::memberId()) !== null) {
            $id = Database::query('SELECT id FROM carts WHERE user_id = ?', [$uid])->fetchColumn();
            if ($id === false && $create) {
                Database::query('INSERT INTO carts (user_id) VALUES (?)', [$uid]);
                return (int) Database::connection()->lastInsertId();
            }
            return $id === false ? null : (int) $id;
        }

        if (($token = self::token()) !== null) {
            $id = Database::query('SELECT id FROM carts WHERE session_hash = ?', [hash('sha256', $token)])->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }
        if (!$create) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        Database::query('INSERT INTO carts (session_hash) VALUES (?)', [hash('sha256', $token)]);
        self::setCookie($token, time() + self::GUEST_DAYS * 86400);
        $_COOKIE[self::COOKIE] = $token; // visible to the rest of this request
        return (int) Database::connection()->lastInsertId();
    }

    public static function count(): int
    {
        $id = self::id();
        return $id === null ? 0 : (int) Database::query('SELECT COALESCE(SUM(qty), 0) FROM cart_items WHERE cart_id = ?', [$id])->fetchColumn();
    }

    /** @return string|null an error message, or null on success */
    public static function add(int $productId, ?int $variantId, int $qty): ?string
    {
        $qty = max(1, min(self::MAX_QTY, $qty));
        $product = Product::findActive($productId);
        if ($product === null || $product['status'] !== 'active') {
            return 'This product is not available.';
        }

        $hasVariants = (int) Database::query('SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND deleted_at IS NULL', [$productId])->fetchColumn() > 0;
        if ($hasVariants) {
            if ($variantId === null) {
                return 'Please choose an option first.';
            }
            $v = Database::query('SELECT stock FROM product_variants WHERE id = ? AND product_id = ? AND deleted_at IS NULL', [$variantId, $productId])->fetch();
            if (!$v) {
                return 'That option is not available.';
            }
            $stock = (int) $v['stock'];
        } else {
            if ($variantId !== null) {
                return 'That option is not available.';
            }
            $stock = (int) $product['stock'];
        }
        if ($stock < 1) {
            return 'Sorry, that item is sold out.';
        }

        $cartId = self::id(true);
        $have = (int) Database::query(
            'SELECT qty FROM cart_items WHERE cart_id = ? AND product_id = ? AND variant_key = ?',
            [$cartId, $productId, $variantId ?? 0]
        )->fetchColumn();
        if ($have + $qty > $stock) {
            return $have > 0
                ? "Only {$stock} in stock and you already have {$have} in your cart."
                : "Only {$stock} in stock.";
        }

        Database::query(
            'INSERT INTO cart_items (cart_id, product_id, variant_id, qty) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE qty = VALUES(qty) + qty',
            [$cartId, $productId, $variantId, $qty]
        );
        Database::query('UPDATE carts SET updated_at = NOW() WHERE id = ?', [$cartId]);
        return null;
    }

    /**
     * Cart lines with live prices + availability. `qty` is what will actually be bought
     * (requested qty capped to current stock); `requested` is what is stored.
     *
     * @return array{items: array, subtotal_cents: int, count: int, has_problem: bool}
     */
    public static function summary(): array
    {
        $cartId = self::id();
        $items = [];
        $subtotal = 0;
        $count = 0;
        $problem = false;

        if ($cartId !== null) {
            $rows = Database::query(
                'SELECT ci.id AS item_id, ci.qty AS requested, ci.product_id, ci.variant_id,
                        p.name, p.slug, p.status, p.deleted_at, p.stock AS p_stock, p.retail_price, p.discount_price, p.discount_start, p.discount_end,
                        v.id AS v_id, v.stock AS v_stock, v.retail_price AS v_retail, v.discount_price AS v_discount, v.deleted_at AS v_deleted,
                        (SELECT i.path FROM product_images i WHERE i.product_id = p.id ORDER BY i.is_primary DESC, i.id LIMIT 1) AS image
                 FROM cart_items ci
                 JOIN products p ON p.id = ci.product_id
                 LEFT JOIN product_variants v ON v.id = ci.variant_id
                 WHERE ci.cart_id = ? ORDER BY ci.id',
                [$cartId]
            )->fetchAll();

            foreach ($rows as $r) {
                $variant = $r['variant_id'] !== null ? ['retail_price' => $r['v_retail'], 'discount_price' => $r['v_discount']] : null;
                $price = PricingService::effective($r, $variant);
                $stock = (int) ($r['variant_id'] !== null ? ($r['v_stock'] ?? 0) : $r['p_stock']);
                $gone = $r['deleted_at'] !== null || $r['status'] !== 'active' || ($r['variant_id'] !== null && ($r['v_id'] === null || $r['v_deleted'] !== null));
                $available = !$gone && $stock > 0;
                $qty = $available ? min((int) $r['requested'], $stock) : 0;
                $unit = PricingService::cents($price['price']);

                $items[] = [
                    'item_id' => (int) $r['item_id'],
                    'product_id' => (int) $r['product_id'],
                    'variant_id' => $r['variant_id'] !== null ? (int) $r['variant_id'] : null,
                    'name' => $r['name'],
                    'slug' => $r['slug'],
                    'variant_label' => $r['variant_id'] !== null && !$gone ? VariantService::label((int) $r['variant_id']) : null,
                    'image' => $r['image'],
                    'unit' => $price['price'],
                    'unit_cents' => $unit,
                    'retail' => $price['retail'],
                    'on_sale' => $price['on_sale'],
                    'requested' => (int) $r['requested'],
                    'qty' => $qty,
                    'stock' => $stock,
                    'available' => $available,
                    'adjusted' => $available && $qty < (int) $r['requested'],
                    'line_cents' => $unit * $qty,
                ];
                $subtotal += $unit * $qty;
                $count += $qty;
                $problem = $problem || !$available || $qty < (int) $r['requested'];
            }
        }
        return ['items' => $items, 'subtotal_cents' => $subtotal, 'count' => $count, 'has_problem' => $problem];
    }

    /** The cart as the storefront scripts want it (mini-cart drawer, cart page updates). Money is pre-formatted. */
    public static function payload(): array
    {
        $c = self::summary();
        $ship = \App\Models\Setting::shippingCents();
        return [
            'count' => $c['count'],
            'subtotal' => money($c['subtotal_cents'] / 100),
            'subtotal_cents' => $c['subtotal_cents'],
            'shipping' => $ship > 0 ? money($ship / 100) : null,
            'total' => money(($c['subtotal_cents'] + ($c['items'] === [] ? 0 : $ship)) / 100),
            'has_problem' => $c['has_problem'],
            'items' => array_map(fn($l) => [
                'item_id' => $l['item_id'], 'name' => $l['name'], 'variant' => $l['variant_label'],
                'href' => url('/products/' . $l['slug']), 'image' => $l['image'] ? url($l['image']) : null,
                'qty' => $l['available'] ? $l['qty'] : $l['requested'], 'stock' => $l['stock'], 'available' => $l['available'], 'adjusted' => $l['adjusted'],
                'unit' => money($l['unit']), 'was' => $l['on_sale'] ? money($l['retail']) : null, 'line' => money($l['line_cents'] / 100),
            ], $c['items']),
        ];
    }

    /** @param array<int,int> $quantities item_id => qty. Returns a notice if anything was capped. */
    public static function update(array $quantities): ?string
    {
        $cartId = self::id();
        if ($cartId === null) {
            return null;
        }
        $capped = false;
        foreach (self::summary()['items'] as $line) {
            if (!isset($quantities[$line['item_id']])) {
                continue;
            }
            $want = (int) $quantities[$line['item_id']];
            if ($want <= 0) {
                Database::query('DELETE FROM cart_items WHERE id = ? AND cart_id = ?', [$line['item_id'], $cartId]);
                continue;
            }
            $max = $line['available'] ? min($line['stock'], self::MAX_QTY) : self::MAX_QTY;
            if ($want > $max) {
                $want = $max;
                $capped = true;
            }
            Database::query('UPDATE cart_items SET qty = ? WHERE id = ? AND cart_id = ?', [$want, $line['item_id'], $cartId]);
        }
        Database::query('UPDATE carts SET updated_at = NOW() WHERE id = ?', [$cartId]);
        return $capped ? 'Some quantities were lowered to what is in stock.' : null;
    }

    public static function remove(int $itemId): void
    {
        $cartId = self::id();
        if ($cartId !== null) {
            Database::query('DELETE FROM cart_items WHERE id = ? AND cart_id = ?', [$itemId, $cartId]);
        }
    }

    /** On member login/registration: fold the anonymous cart (if any) into the member's cart. */
    public static function mergeGuestIntoMember(int $userId): void
    {
        $token = self::token();
        if ($token === null) {
            return;
        }
        $guestId = Database::query('SELECT id FROM carts WHERE session_hash = ? AND user_id IS NULL', [hash('sha256', $token)])->fetchColumn();
        if ($guestId === false) {
            self::setCookie('', time() - 3600);
            return;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $mine = Database::query('SELECT id FROM carts WHERE user_id = ?', [$userId])->fetchColumn();
            if ($mine === false) {
                Database::query('UPDATE carts SET user_id = ?, session_hash = NULL WHERE id = ?', [$userId, $guestId]);
            } else {
                $lines = Database::query('SELECT product_id, variant_id, qty FROM cart_items WHERE cart_id = ?', [$guestId])->fetchAll();
                foreach ($lines as $l) {
                    Database::query(
                        'INSERT INTO cart_items (cart_id, product_id, variant_id, qty) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE qty = LEAST(' . self::MAX_QTY . ', qty + ?)',
                        [$mine, $l['product_id'], $l['variant_id'], $l['qty'], $l['qty']]
                    );
                }
                Database::query('DELETE FROM carts WHERE id = ?', [$guestId]); // cascades its items
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::setCookie('', time() - 3600);
        unset($_COOKIE[self::COOKIE]);
    }

    /** Housekeeping for bin/prune-carts.php: drop anonymous carts untouched for GUEST_DAYS. */
    public static function pruneGuestCarts(): int
    {
        return Database::query(
            'DELETE FROM carts WHERE user_id IS NULL AND updated_at < (NOW() - INTERVAL ' . self::GUEST_DAYS . ' DAY)'
        )->rowCount();
    }
}
