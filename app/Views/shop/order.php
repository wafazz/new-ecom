<div class="page-head"><h1>Order <?= e($order['order_no']) ?></h1></div>
<?php require __DIR__ . '/_tracker.php'; ?>
<?php if ($order['user_id'] === null): ?>
    <p class="notice"><strong>Save this page's link</strong>: it is the only way to see your order without an account. Email notifications are not set up yet, so we can't send it to you.
        You can also look it up any time with your order number and email on the <a href="<?= e(url('/track')) ?>">track order</a> page.</p>
<?php endif; ?>
<div class="co-card order-card"><?php require BASE_PATH . '/app/Views/orders/_detail.php'; ?></div>
<?php require BASE_PATH . '/app/Views/orders/_proof.php'; ?>
<?php require BASE_PATH . '/app/Views/orders/_pay.php'; ?>
<?php require BASE_PATH . '/app/Views/orders/_cancel.php'; ?>
