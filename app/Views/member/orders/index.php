<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>My orders</h1>
            <p class="muted">Every order you have placed. Open one for its status, tracking and payment.</p>
        </div>
        <span class="msg-pill"><?= count($orders) ?> order<?= count($orders) === 1 ? '' : 's' ?></span>
    </header>

    <section class="msg-card">
        <?php if ($orders === []): ?>
            <p class="msg-empty">No orders yet. <a href="<?= e(url('/products')) ?>">Start shopping</a></p>
        <?php else: ?>
        <div class="table-wrap">
        <table class="msg-table">
            <thead><tr><th>Order</th><th>Placed</th><th>Status</th><th>Payment</th><th class="num">Total</th><th>Invoice</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td><a href="<?= e(url('/member/orders/' . $o['order_no'])) ?>"><strong><?= e($o['order_no']) ?></strong></a></td>
                    <td><small><?= e($o['created_at']) ?></small></td>
                    <td><span class="badge st-<?= e($o['status']) ?>"><?= e($o['status']) ?></span></td>
                    <td><span class="msg-pill <?= $o['payment_status'] === 'paid' ? 'ok' : 'warn' ?>"><?= e($o['payment_status']) ?></span></td>
                    <td class="num"><?= e(money($o['total'])) ?></td>
                    <td><?php if ($o['status'] !== 'cancelled'): ?><a href="<?= e(url('/member/orders/' . $o['order_no'] . '/invoice')) ?>" data-invoice-open target="_blank" rel="noopener">Invoice</a><?php else: ?><span class="muted">–</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
</div>
