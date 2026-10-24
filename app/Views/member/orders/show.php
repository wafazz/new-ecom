<div class="msg-page">
<header class="msg-head">
    <div>
        <p class="muted" style="margin:0"><a href="<?= e(url('/member/orders')) ?>">← My orders</a></p>
        <h1>Order <?= e($order['order_no']) ?></h1>
    </div>
    <?php if ($order['status'] !== 'cancelled'): ?><a class="btn secondary" href="<?= e(url('/member/orders/' . $order['order_no'] . '/invoice')) ?>" data-invoice-open target="_blank" rel="noopener">View invoice</a><?php endif; ?>
</header>
<?php require BASE_PATH . '/app/Views/orders/_detail.php'; ?>
<?php require BASE_PATH . '/app/Views/orders/_proof.php'; ?>
<?php require BASE_PATH . '/app/Views/orders/_pay.php'; ?>
<?php require BASE_PATH . '/app/Views/orders/_cancel.php'; ?>
</div>
