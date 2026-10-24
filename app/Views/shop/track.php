<div class="narrow-page">
    <div class="page-head center"><span class="big-ico"><?= icon('pin', 'i i-xl') ?></span><h1>Track your order</h1><p class="muted">Enter your order number and the email you used at checkout.</p></div>
    <form method="post" action="<?= e(url('/track')) ?>" class="co-card track-form" novalidate>
        <?= csrf_field() ?>
        <label>Order number <input name="order_no" value="<?= old('order_no') ?>" placeholder="ORD-XXXXXXXXXX" autocomplete="off" required></label>
        <label>Email used at checkout <input type="email" name="email" autocomplete="email" required></label>
        <button class="s-btn lg block">Find my order</button>
    </form>
</div>
