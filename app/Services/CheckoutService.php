<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Setting;
use App\Models\User;

final class CheckoutService
{
    /**
     * Turn a cart into an order in ONE transaction: lock the cart and every product/variant row,
     * re-check availability and stock, re-price from the live data, snapshot everything, deduct
     * stock, empty the cart. A second concurrent submit finds the cart empty and fails cleanly.
     *
     * @param array $contact  name,email,phone,line1,line2,city,state,postcode,notes
     * @param array|null $newAccount ['password' => ...] to register the buyer in the same transaction
     * @param string|null $refCode affiliate code from the `ref` cookie; ignored when unknown or a self-referral
     * @return array{order_id:int, order_no:string, token:?string, user_id:?int}
     * @throws CheckoutException
     */
    public static function place(int $cartId, array $contact, string $paymentMethod, ?int $userId, ?array $newAccount = null, ?string $refCode = null): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Database::query('SELECT id FROM carts WHERE id = ? FOR UPDATE', [$cartId]);
            $lines = Database::query('SELECT product_id, variant_id, qty FROM cart_items WHERE cart_id = ? ORDER BY product_id, variant_id', [$cartId])->fetchAll();
            if ($lines === []) {
                throw new CheckoutException('Your cart is empty.');
            }

            $items = [];
            $subtotal = 0;
            foreach ($lines as $l) {
                $p = Database::query('SELECT * FROM products WHERE id = ? FOR UPDATE', [$l['product_id']])->fetch();
                if (!$p || $p['deleted_at'] !== null || $p['status'] !== 'active') {
                    throw new CheckoutException('An item in your cart is no longer available. Please review your cart.');
                }
                $v = null;
                if ($l['variant_id'] !== null) {
                    $v = Database::query('SELECT * FROM product_variants WHERE id = ? AND product_id = ? AND deleted_at IS NULL FOR UPDATE', [$l['variant_id'], $p['id']])->fetch();
                    if (!$v) {
                        throw new CheckoutException("An option of \"{$p['name']}\" is no longer available. Please review your cart.");
                    }
                } elseif ((int) Database::query('SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND deleted_at IS NULL', [$p['id']])->fetchColumn() > 0) {
                    throw new CheckoutException("\"{$p['name']}\" now needs an option chosen. Please review your cart.");
                }

                $stock = (int) ($v['stock'] ?? $p['stock']);
                if ($l['qty'] > $stock) {
                    throw new CheckoutException($stock < 1 ? "\"{$p['name']}\" just sold out." : "Only {$stock} left of \"{$p['name']}\". Please lower the quantity in your cart.");
                }

                $eff = PricingService::effective($p, $v);
                $cost = PricingService::resolveVariant($p, $v ?? []);
                $unit = PricingService::cents($eff['price']);
                $subtotal += $unit * (int) $l['qty'];
                $items[] = [
                    'product_id' => (int) $p['id'],
                    'variant_id' => $v ? (int) $v['id'] : null,
                    'name' => $p['name'],
                    'label' => $v ? VariantService::label((int) $v['id']) : null,
                    'sku' => $v['sku'] ?? $p['sku'],
                    'unit' => $unit,
                    'retail' => PricingService::cents($eff['retail']),
                    'base' => PricingService::cents((string) $cost['base_price']),
                    'company' => PricingService::cents((string) $cost['company_base_price']),
                    'qty' => (int) $l['qty'],
                    'rate' => AffiliateService::rateFor($p['commission_rate'] !== null ? (string) $p['commission_rate'] : null),
                ];
            }

            if ($newAccount !== null) {
                try {
                    $userId = User::create('member', $contact['name'], $contact['email'], $newAccount['password'], $contact['phone']);
                } catch (\PDOException $e) {
                    if ($e->getCode() === '23000') {
                        throw new CheckoutException('That email already has an account. Please log in to check out.');
                    }
                    throw $e;
                }
            }

            // Attribution: only a real, active referrer who is not the buyer earns commission.
            $aff = AffiliateService::findByCode($refCode);
            if ($aff !== null && AffiliateService::isSelfReferral($aff, $userId, $contact['email'])) {
                $aff = null;
            }
            $affiliateId = $aff !== null ? (int) $aff['id'] : null;

            $shipping = Setting::shippingCents();
            $token = $userId === null ? bin2hex(random_bytes(16)) : null;
            $orderNo = self::newOrderNo();

            Database::query(
                'INSERT INTO orders (order_no, user_id, customer_name, customer_email, customer_phone, ship_line1, ship_line2, ship_city, ship_state, ship_postcode,
                                     subtotal, shipping_fee, total, payment_method, track_hash, notes, affiliate_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $orderNo, $userId, $contact['name'], strtolower($contact['email']), $contact['phone'],
                    $contact['line1'], $contact['line2'] ?: null, $contact['city'], $contact['state'], $contact['postcode'],
                    PricingService::format($subtotal), PricingService::format($shipping), PricingService::format($subtotal + $shipping),
                    $paymentMethod, $token === null ? null : hash('sha256', $token), $contact['notes'] ?: null, $affiliateId,
                ]
            );
            $orderId = (int) $pdo->lastInsertId();

            foreach ($items as $it) {
                Database::query(
                    'INSERT INTO order_items (order_id, product_id, variant_id, product_name, variant_label, sku, unit_price, retail_price, base_price, company_base_price, qty, line_total, commission_rate)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$orderId, $it['product_id'], $it['variant_id'], $it['name'], $it['label'], $it['sku'],
                     PricingService::format($it['unit']), PricingService::format($it['retail']), PricingService::format($it['base']), PricingService::format($it['company']),
                     $it['qty'], PricingService::format($it['unit'] * $it['qty']), $affiliateId !== null ? $it['rate'] : null]
                );
                StockService::delta($it['product_id'], $it['variant_id'], -$it['qty'], 'order', $orderId, $userId); // locks the row, logs the movement
            }

            Database::query('INSERT INTO order_events (order_id, event, note, actor_id) VALUES (?, ?, ?, ?)', [$orderId, 'placed', 'Order placed', $userId]);
            Database::query('DELETE FROM cart_items WHERE cart_id = ?', [$cartId]);
            $pdo->commit();
            return ['order_id' => $orderId, 'order_no' => $orderNo, 'token' => $token, 'user_id' => $userId];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** ORD- + 10 chars of an unambiguous alphabet (~50 bits): not guessable, easy to read out. */
    private static function newOrderNo(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        do {
            $no = 'ORD-';
            for ($i = 0; $i < 10; $i++) {
                $no .= $alphabet[random_int(0, 31)];
            }
        } while ((int) Database::query('SELECT COUNT(*) FROM orders WHERE order_no = ?', [$no])->fetchColumn() > 0);
        return $no;
    }
}
