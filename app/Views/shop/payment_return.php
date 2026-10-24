<div class="narrow-page">
    <div class="co-card result-card <?= $paid ? 'ok' : 'wait' ?>">
        <span class="result-ico"><?= icon($paid ? 'check' : 'pin', 'i i-xl') ?></span>
        <h1><?= $paid ? 'Payment received' : 'Payment not completed' ?></h1>
        <?php if ($paid): ?>
            <p>Thank you! Your payment for order <strong><?= e($orderNo) ?></strong> was received and we'll start preparing it.</p>
        <?php else: ?>
            <p>We have not received a payment for order <strong><?= e($orderNo) ?></strong> yet. If you were charged, it can take a few minutes to show up.</p>
            <p>To try again, open the order link you were given when you checked out, and use <em>Pay now</em>. Your items are held for a limited time.</p>
        <?php endif; ?>
        <p class="muted">Lost the link? Look the order up on the <a href="<?= e(url('/track')) ?>">track order</a> page with your order number and email.</p>
        <p><a class="s-btn" href="<?= e(url('/products')) ?>">Continue shopping</a></p>
    </div>
</div>
