<?php if (!$popup): ?>
<p><a href="<?= e(url($base . '/orders')) ?>">← Orders</a><?php if ($order['status'] !== 'cancelled'): ?> · <a href="<?= e(url($base . '/orders/' . (int) $order['id'] . '/invoice')) ?>" data-invoice-open target="_blank" rel="noopener">View invoice</a><?php endif; ?></p>
<h1>Order <?= e($order['order_no']) ?></h1>
<?php endif; ?>
<?php $showCost = $isAdmin; require BASE_PATH . '/app/Views/orders/_detail.php'; ?>

<?php require BASE_PATH . '/app/Views/manage/orders/_proof.php'; ?>

<?php require BASE_PATH . '/app/Views/manage/orders/_shipment.php'; ?>

<?php if ($canUpdate): ?>
<section>
    <h2>Update</h2>
    <?php if ($order['payment_status'] === 'unpaid' && !in_array($order['status'], ['cancelled', 'returned'], true)): ?>
        <form method="post" action="<?= e(url($base . '/orders/' . (int) $order['id'] . '/paid')) ?>" class="inline">
            <?= csrf_field() ?><button class="btn secondary">Mark as paid</button>
        </form>
    <?php endif; ?>
    <?php if ($next === []): ?>
        <p class="muted">This order is <?= e($order['status']) ?> and can't change further.</p>
    <?php else: ?>
        <form method="post" action="<?= e(url($base . '/orders/' . (int) $order['id'] . '/status')) ?>" class="card-form">
            <?= csrf_field() ?>
            <label>Move to
                <select name="status"><?php foreach ($next as $s): ?><option><?= e($s) ?></option><?php endforeach; ?></select></label>
            <label>Note (optional, e.g. tracking number) <input name="note" maxlength="255"></label>
            <button class="btn">Update status</button>
            <small class="muted">Cancelling returns the items to stock.</small>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>
