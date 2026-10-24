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
