<p><a href="<?= e(url('/member/orders')) ?>">← My orders</a></p>
<h1>Order <?= e($order['order_no']) ?></h1>
<?php require BASE_PATH . '/app/Views/orders/_detail.php'; ?>
<?php require BASE_PATH . '/app/Views/orders/_proof.php'; ?>
<?php require BASE_PATH . '/app/Views/orders/_pay.php'; ?>
<?php require BASE_PATH . '/app/Views/orders/_cancel.php'; ?>
