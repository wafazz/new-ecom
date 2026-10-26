<?php
/** Expects: $order, $items, $events, $payment; optional $showCost (admin only). */
$showCost = $showCost ?? false;
$ship = array_filter([$order['ship_line1'], $order['ship_line2'], $order['ship_postcode'] . ' ' . $order['ship_city'], $order['ship_state'], 'Malaysia']);
$cost = 0;
?>
<div class="order-head">
    <div><strong><?= e($order['order_no']) ?></strong><br><small class="muted">Placed <?= e($order['created_at']) ?></small></div>
    <div>
        <span class="badge st-<?= e($order['status']) ?>"><?= e($order['status']) ?></span>
        <span class="badge <?= $order['payment_status'] === 'paid' ? 'active' : 'draft' ?>"><?= e($order['payment_status']) ?></span>
    </div>
</div>

<table>
    <thead><tr><th>Item</th><th class="num">Price</th><th class="num">Qty</th><th class="num">Total</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): $cost += (float) $it['base_price'] * $it['qty']; ?>
        <tr>
            <td><?= e($it['product_name']) ?><?php if ($it['variant_label']): ?><br><small class="muted"><?= e($it['variant_label']) ?></small><?php endif; ?>
                <?php if ($it['sku']): ?><br><small class="muted">SKU <?= e($it['sku']) ?></small><?php endif; ?></td>
            <td class="num"><?= e(money($it['unit_price'])) ?></td>
            <td class="num"><?= (int) $it['qty'] ?></td>
            <td class="num"><?= e(money($it['line_total'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr><td colspan="3" class="num">Subtotal</td><td class="num"><?= e(money($order['subtotal'])) ?></td></tr>
        <?php if ((float) $order['discount_total'] > 0): ?>
            <tr><td colspan="3" class="num">Discount<?= $order['voucher_code'] ? ' (' . e($order['voucher_code']) . ')' : '' ?></td><td class="num">−<?= e(money($order['discount_total'])) ?></td></tr>
        <?php endif; ?>
        <tr><td colspan="3" class="num">Shipping</td><td class="num"><?= e(money($order['shipping_fee'])) ?></td></tr>
        <tr><td colspan="3" class="num"><strong>Total</strong></td><td class="num"><strong><?= e(money($order['total'])) ?></strong></td></tr>
        <?php if ($showCost): ?>
            <tr class="muted"><td colspan="3" class="num">Cost of goods (admin only)</td><td class="num"><?= e(money($cost)) ?></td></tr>
            <tr class="muted"><td colspan="3" class="num">Gross profit (subtotal − discount − cost)</td><td class="num"><?= e(money((float) $order['subtotal'] - (float) $order['discount_total'] - $cost)) ?></td></tr>
        <?php endif; ?>
    </tfoot>
</table>
<?php
$digitalItems = array_values(array_filter($items, fn($it) => $it['digital_delivery'] !== null));
if ($digitalItems !== [] && !$showCost): // the buyer's view: downloads and keys (the admin view only sees the order)
    $grants = \App\Services\DigitalService::forOrder((int) $order['id']);
    $paid = $order['payment_status'] === 'paid' && !in_array($order['status'], ['cancelled', 'returned'], true);
?>
<section class="digital-delivery">
    <h3>Your digital products</h3>
    <?php if (!$paid): ?>
        <p class="muted">Downloads and license keys appear here once the payment is confirmed.</p>
    <?php else: ?>
        <ul>
        <?php foreach ($digitalItems as $it): ?>
            <li>
                <strong><?= e($it['product_name']) ?></strong>
                <?php if ($it['digital_delivery'] === 'keys'): ?>
                    <?php $keys = \App\Services\DigitalService::keysFor((int) $it['id']); ?>
                    <?php if ($keys === []): ?>
                        <br><small class="muted">The license key is being prepared. Refresh this page shortly, or contact the shop.</small>
                    <?php else: ?>
                        <ul class="license-keys">
                            <?php foreach ($keys as $k): ?><li><code><?= e($k) ?></code></li><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php else: ?>
                    <?php foreach ($grants as $g): if ((int) $g['order_item_id'] !== (int) $it['id']) continue; ?>
                        <br>
                        <?php $left = (int) $g['max_downloads'] - (int) $g['downloads']; $open = strtotime($g['expires_at']) > time(); ?>
                        <?php if ($g['url'] !== null && $left > 0 && $open): ?>
                            <a class="btn btn-small" href="<?= e($g['url']) ?>"><?= $g['delivery'] === 'link' ? 'Open link' : 'Download' ?></a>
                            <small class="muted"><?= $g['delivery'] === 'file' ? e($g['file_name']) . ' · ' : '' ?><?= $left ?> download(s) left · open until <?= e(date('j M Y', strtotime($g['expires_at']))) ?></small>
                        <?php elseif (!$open): ?>
                            <small class="muted">This download has expired. Contact the shop if you need it again.</small>
                        <?php elseif ($left <= 0): ?>
                            <small class="muted">You have used all the downloads for this product. Contact the shop if you need more.</small>
                        <?php else: ?>
                            <small class="muted">This link cannot be shown right now. Contact the shop.</small>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php endif; ?>

<div class="grid2 info">
    <div>
        <h3>Deliver to</h3>
        <p><?= e($order['customer_name']) ?><br><?= e($order['customer_phone']) ?><br><?= e($order['customer_email']) ?></p>
        <p><?= implode('<br>', array_map('e', $ship)) ?></p>
        <?php if ($order['notes']): ?><p><small class="muted">Note:</small> <?= e($order['notes']) ?></p><?php endif; ?>
    </div>
    <div>
        <h3>Payment</h3>
        <p><strong><?= e($payment ? $payment->label() : $order['payment_method']) ?></strong></p>
        <?php if (in_array($order['status'], ['cancelled', 'returned'], true) && $order['payment_status'] === 'paid'): ?>
            <p class="danger"><strong>Paid, but the order is <?= e($order['status']) ?>: a refund is due.</strong></p>
        <?php endif; ?>
        <?php if ($payment && $order['payment_status'] === 'unpaid' && !in_array($order['status'], ['cancelled', 'returned'], true)): ?>
            <?php require __DIR__ . '/_bank.php'; ?>
        <?php endif; ?>
    </div>
</div>

<?php $__ship = \App\Services\ShipmentService::active((int) $order['id']); if ($__ship && $order['status'] !== 'cancelled'): ?>
<h3>Shipment</h3>
<p>NinjaVan tracking number <strong><?= e($__ship['tracking_number']) ?></strong> · <?= e($__ship['status']) ?></p>
<?php endif; ?>

<h3>History</h3>
<ul class="timeline">
    <?php foreach ($events as $ev): ?>
        <li><strong><?= e($ev['event']) ?></strong> <small class="muted"><?= e($ev['created_at']) ?><?= $ev['actor'] ? ' · ' . e($ev['actor']) : '' ?></small>
            <?php if ($ev['note'] && $ev['note'] !== 'Order placed'): ?><br><?= e($ev['note']) ?><?php endif; ?></li>
    <?php endforeach; ?>
</ul>
