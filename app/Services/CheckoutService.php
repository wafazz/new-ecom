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
     * @param string|null $voucherCode discount code; checked again here against the live subtotal
     * @return array{order_id:int, order_no:string, token:?string, user_id:?int}
     * @throws CheckoutException
     */
    public static function place(int $cartId, array $contact, string $paymentMethod, ?int $userId, ?array $newAccount = null, ?string $refCode = null, ?string $voucherCode = null, int $pointsRequested = 0, ?string $giftCode = null, bool $useCredit = false): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Database::query('SELECT id FROM carts WHERE id = ? FOR UPDATE', [$cartId]);
            // Strict hold: a line whose hold has lapsed by the time the order is placed is refused, not sold.
            $lapsed = (int) Database::query('SELECT COUNT(*) FROM cart_items WHERE cart_id = ? AND (reserved_until IS NULL OR reserved_until <= NOW())', [$cartId])->fetchColumn();
            if ($lapsed > 0) {
                throw new CheckoutException(CartService::EXPIRED_MESSAGE);
            }
            $lines = Database::query('SELECT product_id, variant_id, qty FROM cart_items WHERE cart_id = ? ORDER BY product_id, variant_id', [$cartId])->fetchAll();
            if ($lines === []) {
                throw new CheckoutException('Your cart is empty.');
            }

            // Quantity price breaks look at the total of each product across its options, so count them first.
            $productQty = [];
            foreach ($lines as $ql) {
                $productQty[(int) $ql['product_id']] = ($productQty[(int) $ql['product_id']] ?? 0) + (int) $ql['qty'];
            }
            $items = [];
            $shipLines = []; // weight and quantity per line, for postage (digital lines are marked and skipped there)
            $subtotal = 0;
            $hasDigital = false;
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

                $digital = DigitalService::isDigital($p);
                if (BundleService::isBundle((int) $p['id']) && !Modules::enabled('bundles')) {
                    throw new CheckoutException("\"{$p['name']}\" is a bundle, and bundles are not available right now. Please remove it from your cart.");
                }
                // A cart filled before the switch was turned off cannot be paid for while it is off.
                if ($digital && !Modules::enabled('digital_products')) {
                    throw new CheckoutException("\"{$p['name']}\" is a digital product, and digital products are not available right now. Please remove it from your cart.");
                }
                $stock = $digital ? DigitalService::stock($p) : (int) ($v['stock'] ?? $p['stock']);
                // Units held by other carts are not free for this order.
                $stock = max(0, $stock - CartService::heldByOthers((int) $p['id'], (int) ($l['variant_id'] ?? 0), $cartId));
                if ($l['qty'] > $stock) {
                    throw new CheckoutException($stock < 1 ? "\"{$p['name']}\" just sold out." : "Only {$stock} left of \"{$p['name']}\". Please lower the quantity in your cart.");
                }

                $p = FlashSaleService::attach([$p])[0]; // the charged price includes any running flash sale
                $eff = PricingService::effective($p, $v);
                $cost = PricingService::resolveVariant($p, $v ?? []);
                $memberUnit = MemberPriceService::priceFor($userId, (int) $p['id'], PricingService::cents($eff['price']));
                $unit = PriceBreakService::unitFor((int) $p['id'], $productQty[(int) $p['id']] ?? 0, $memberUnit);
                $subtotal += $unit * (int) $l['qty'];
                $shipLines[] = ['weight_kg' => $p['weight_kg'], 'qty' => (int) $l['qty'], 'digital' => $digital];
                $hasDigital = $hasDigital || $digital;
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
                    'delivery' => $digital ? $p['digital_delivery'] : null,
                ];
            }

            if ($hasDigital && $paymentMethod === 'cod') {
                throw new CheckoutException('Cash on delivery is not available for digital products. Please choose another payment method.');
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

            // A discount code is checked against this live subtotal and used up in this same transaction.
            $voucher = null;
            $discount = 0;
            if ($voucherCode !== null && $voucherCode !== '') {
                $q = VoucherService::quote($voucherCode, $subtotal);
                if (!$q['ok']) {
                    throw new CheckoutException($q['message'] . ' Remove it and try again.');
                }
                $voucher = $q['voucher'];
                $discount = $q['discount'];
                VoucherService::redeem((int) $voucher['id']);
            }

            // Loyalty points: members can spend up to half of the items' value; the ledger is locked while it is checked.
            $pointsUsed = 0;
            if ($userId !== null && $pointsRequested > 0 && Modules::enabled('loyalty')) {
                $lp = LoyaltyService::discountFor($userId, $subtotal - $discount, $pointsRequested);
                $pointsUsed = $lp['points'];
                $discount += $lp['cents'];
            }

            // Gift card: covers up to its balance and no more than the items left after the discounts above.
            $giftCardId = null;
            $giftUsed = 0;
            if ($giftCode !== null && $giftCode !== '' && Modules::enabled('gift_cards')) {
                $g = GiftCardService::reserve($giftCode, $subtotal - $discount);
                $giftCardId = $g['id'];
                $giftUsed = $g['cents'];
                $discount += $giftUsed;
            }

            // Store credit: a member's balance, up to the items left after the discounts above.
            $creditUsed = 0;
            if ($userId !== null && $useCredit && Modules::enabled('store_credit')) {
                $creditUsed = StoreCreditService::reserve($userId, $subtotal - $discount);
                $discount += $creditUsed;
            }

            // Postage is worked out here from the zone, weight and payment method. The browser never sets it.
            $quote = ShippingRates::quote($shipLines, $subtotal - $discount, (string) $contact['state'], $paymentMethod);
            $shipping = $quote['fee'];
            $token = $userId === null ? bin2hex(random_bytes(16)) : null;
            $orderNo = self::newOrderNo();
            // SST is part of the price already paid: worked out here from the final discount, stored on the order and each item.
            $taxTotal = TaxService::split($items, $subtotal, $discount);

            Database::query(
                'INSERT INTO orders (order_no, user_id, customer_name, customer_email, customer_phone, ship_line1, ship_line2, ship_city, ship_state, ship_postcode,
                                     subtotal, shipping_fee, total, payment_method, track_hash, notes, affiliate_id, voucher_id, voucher_code, discount_total,
                                     shipping_zone, billable_kg, cod_fee, tax_total)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $orderNo, $userId, $contact['name'], strtolower($contact['email']), $contact['phone'],
                    $contact['line1'], $contact['line2'] ?: null, $contact['city'], $contact['state'], $contact['postcode'],
                    PricingService::format($subtotal), PricingService::format($shipping), PricingService::format($subtotal - $discount + $shipping),
                    $paymentMethod, $token === null ? null : hash('sha256', $token), $contact['notes'] ?: null, $affiliateId,
                    $voucher === null ? null : (int) $voucher['id'], $voucher === null ? null : $voucher['code'], PricingService::format($discount),
                    $quote['zone'], $quote['kg'], PricingService::format($quote['cod']), PricingService::format($taxTotal),
                ]
            );
            $orderId = (int) $pdo->lastInsertId();
            if ($userId !== null && $pointsUsed > 0) {
                LoyaltyService::spend($orderId, $userId, $pointsUsed);
            }
            if ($giftCardId !== null && $giftUsed > 0) {
                GiftCardService::spend($orderId, $giftCardId, $giftUsed);
            }
            if ($userId !== null && $creditUsed > 0) {
                StoreCreditService::spend($orderId, $userId, $creditUsed);
            }

            foreach ($items as $it) {
                Database::query(
                    'INSERT INTO order_items (order_id, product_id, variant_id, product_name, variant_label, sku, unit_price, retail_price, base_price, company_base_price, qty, line_total, commission_rate, digital_delivery, tax_rate, tax_amount)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$orderId, $it['product_id'], $it['variant_id'], $it['name'], $it['label'], $it['sku'],
                     PricingService::format($it['unit']), PricingService::format($it['retail']), PricingService::format($it['base']), PricingService::format($it['company']),
                     $it['qty'], PricingService::format($it['unit'] * $it['qty']), $affiliateId !== null ? $it['rate'] : null, $it['delivery'], $it['tax_rate'], PricingService::format($it['tax_amount'])]
                );
                if ($it['delivery'] === null) {
                    BundleService::adjustLine((int) $it['product_id'], $it['variant_id'] !== null ? (int) $it['variant_id'] : null, -(int) $it['qty'], 'order', $orderId, $userId); // locks the row, logs the movement
                } // digital items have no stock to take; license keys are assigned when the order is paid
            }

            Database::query('INSERT INTO order_events (order_id, event, note, actor_id) VALUES (?, ?, ?, ?)', [$orderId, 'placed', 'Order placed', $userId]);
            Database::query('DELETE FROM cart_items WHERE cart_id = ?', [$cartId]);
            $pdo->commit();
            // After the commit, and queued: the customer hears that the order is placed, and a slow or failed send never delays checkout.
            Queue::later('whatsapp.status', ['order_id' => $orderId, 'status' => 'pending']);
            Queue::later('email.order_created', ['order_id' => $orderId, 'track_url' => $userId !== null ? url('/member/orders/' . $orderNo) : url('/order/' . $orderNo . '/' . $token)]);
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
