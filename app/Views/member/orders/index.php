<h1>My orders</h1>
<?php if ($orders === []): ?>
    <p class="muted">No orders yet. <a href="<?= e(url('/products')) ?>">Start shopping</a></p>
<?php else: ?>
<table>
    <thead><tr><th>Order</th><th>Placed</th><th>Status</th><th>Payment</th><th class="num">Total</th><th>Invoice</th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
        <tr>
            <td><a href="<?= e(url('/member/orders/' . $o['order_no'])) ?>"><?= e($o['order_no']) ?></a></td>
            <td><?= e($o['created_at']) ?></td>
            <td><span class="badge st-<?= e($o['status']) ?>"><?= e($o['status']) ?></span></td>
            <td><span class="badge <?= $o['payment_status'] === 'paid' ? 'active' : 'draft' ?>"><?= e($o['payment_status']) ?></span></td>
            <td class="num"><?= e(money($o['total'])) ?></td>
            <td><?php if ($o['status'] !== 'cancelled'): ?><a href="<?= e(url('/member/orders/' . $o['order_no'] . '/invoice')) ?>" data-invoice-open target="_blank" rel="noopener">Invoice</a><?php else: ?><span class="muted">–</span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
