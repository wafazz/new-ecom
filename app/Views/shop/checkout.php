<?php
$guest = $member === null;
$val = fn(string $k) => old($k, (string) ($defaults[$k] ?? ''));
$total = $cart['subtotal_cents'] + $shipping_cents;
$count = (int) $cart['count'];
$defaultMethod = isset($methods['cod']) ? 'cod' : (string) array_key_first($methods);
?>
<ol class="steps" aria-label="Checkout progress">
    <li class="done"><span><?= icon('check', 'i i-sm') ?></span> Cart</li>
    <li class="now"><span>2</span> Details &amp; payment</li>
    <li><span>3</span> Confirmation</li>
</ol>
<div class="page-head"><h1>Checkout</h1></div>
<?php if ($guest): ?>
    <p class="notice"><?= icon('user', 'i i-sm') ?> Have an account? <a href="<?= e(url('/login')) ?>">Log in</a> for faster checkout and order history, or just continue as a guest below.</p>
<?php endif; ?>

<button type="button" class="sum-toggle" data-sum-toggle aria-expanded="false" aria-controls="co-summary">
    <span><?= icon('bag') ?> Show order summary <?= icon('down', 'i i-sm') ?></span><strong><?= e(money($total / 100)) ?></strong>
</button>

<div class="checkout" data-checkout>
<form method="post" action="<?= e(url('/checkout')) ?>" novalidate class="co-form" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <section class="co-card">
        <h2><span class="num-dot">1</span> Your details</h2>
        <label>Full name <input name="name" value="<?= $val('name') ?>" autocomplete="name" required><?= field_error('name') ?></label>
        <div class="grid2">
            <label>Email <input type="email" name="email" value="<?= $val('email') ?>" autocomplete="email" <?= $guest ? 'required' : 'disabled' ?>><?= field_error('email') ?>
                <?php if (!$guest): ?><small class="muted">Orders are linked to your account email.</small><?php endif; ?></label>
            <label>Phone <input name="phone" value="<?= $val('phone') ?>" autocomplete="tel" inputmode="tel" required><?= field_error('phone') ?></label>
        </div>
    </section>

    <section class="co-card">
        <h2><span class="num-dot">2</span> Delivery address</h2>
        <?php if ($saved !== []): ?>
            <p class="saved-addresses">Saved addresses:
                <?php foreach ($saved as $a): ?>
                    <a href="<?= e(url('/checkout?address=' . (int) $a['id'])) ?>" class="saved-chip <?= $pickedId === (int) $a['id'] ? 'on' : '' ?>"><?= e($a['label'] ?: $a['line1']) ?><?= $a['is_default'] ? ' (default)' : '' ?></a>
                <?php endforeach; ?>
                <a href="<?= e(url('/member/addresses')) ?>">Manage</a>
            </p>
        <?php endif; ?>
        <label>Address line 1 <input name="line1" value="<?= $val('line1') ?>" autocomplete="address-line1" required><?= field_error('line1') ?></label>
        <label>Address line 2 (optional) <input name="line2" value="<?= $val('line2') ?>" autocomplete="address-line2"><?= field_error('line2') ?></label>
        <div class="grid3">
            <label>City <input name="city" value="<?= $val('city') ?>" autocomplete="address-level2" required><?= field_error('city') ?></label>
            <label>State
                <select name="state" required>
                    <option value="">Select…</option>
                    <?php foreach ($states as $s): ?>
                        <option <?= $val('state') === e($s) ? 'selected' : '' ?>><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select><?= field_error('state') ?></label>
            <label>Postcode <input name="postcode" value="<?= $val('postcode') ?>" inputmode="numeric" maxlength="5" autocomplete="postal-code" required><?= field_error('postcode') ?></label>
        </div>
        <?php if ($canSave): ?>
            <label class="check"><input type="checkbox" name="save_address" value="1" <?= old('save_address', $saved === [] ? '1' : '') === '1' ? 'checked' : '' ?>> Save this address to my account</label>
        <?php endif; ?>
    </section>

    <section class="co-card">
        <h2><span class="num-dot">3</span> Payment</h2>
        <div class="pay-list">
            <?php foreach ($methods as $code => $m): ?>
                <label class="pay">
                    <input type="radio" name="payment_method" value="<?= e($code) ?>" <?= old('payment_method', $defaultMethod) === $code ? 'checked' : '' ?>>
                    <span class="pay-radio" aria-hidden="true"></span>
                    <span class="pay-text"><strong><?= e($m->label()) ?></strong><small class="muted"><?= e($m->instructions()) ?></small></span>
                </label>
            <?php endforeach; ?>
        </div>
        <?= field_error('payment_method') ?>
        <?php if (isset($methods['bank_transfer'])): ?>
        <div class="proof-panel" data-bank-proof<?= old('payment_method', $defaultMethod) === 'bank_transfer' ? '' : ' hidden' ?>>
            <h3>Pay by bank transfer</h3>
            <div class="bank-box">
                <p class="bank-title">Transfer <strong><?= e(money($total / 100)) ?></strong> to this account</p>
                <dl>
                    <div><dt>Bank</dt><dd><?= e($bank['name']) ?></dd></div>
                    <div><dt>Account name</dt><dd><?= e($bank['holder']) ?></dd></div>
                    <div><dt>Account number</dt><dd><strong data-copy-text><?= e($bank['number']) ?></strong> <button type="button" class="copy-btn" data-copy hidden>Copy</button></dd></div>
                </dl>
                <?php if ($bank['note'] !== ''): ?><p><?= e($bank['note']) ?></p><?php endif; ?>
            </div>
            <h3>Then tell us about your payment</h3>
            <p class="muted proof-help">Add the receipt from your bank app or banking site. We check it and confirm your order.</p>
            <?php require BASE_PATH . '/app/Views/partials/proof_fields.php'; ?>
        </div>
        <?php endif; ?>
        <label>Order note (optional) <textarea name="notes" rows="2" maxlength="500" placeholder="Anything we should know about the delivery?"><?= old('notes') ?></textarea></label>
    </section>

    <?php if ($guest): ?>
        <section class="co-card">
            <label class="check"><input type="checkbox" name="create_account" value="1" id="mk" <?= old('create_account') === '1' ? 'checked' : '' ?>> Create an account with this email so I can track orders</label>
            <div id="acct" <?= old('create_account') === '1' ? '' : 'hidden' ?>>
                <div class="grid2">
                    <label>Password (min 8) <input type="password" name="password" autocomplete="new-password"><?= field_error('password') ?></label>
                    <label>Confirm password <input type="password" name="password_confirmation" autocomplete="new-password"><?= field_error('password_confirmation') ?></label>
                </div>
            </div>
        </section>
        <script>document.getElementById('mk').addEventListener('change',function(){document.getElementById('acct').hidden=!this.checked;});</script>
    <?php endif; ?>

    <button class="s-btn lg block" data-place-order>Place order · <?= e(money($total / 100)) ?></button>
    <p class="co-fine"><?= icon('shield', 'i i-sm') ?> Your details are only used to deliver this order.</p>
</form>

<aside class="summary" id="co-summary">
    <h2>Order summary <span class="muted">(<?= $count ?>)</span></h2>
    <ul class="co-items">
        <?php foreach ($cart['items'] as $l): ?>
            <li>
                <span class="co-thumb"><?php if ($l['image']): ?><img src="<?= e(url($l['image'])) ?>" alt=""><?php else: ?><?= icon('box') ?><?php endif; ?><b><?= (int) $l['qty'] ?></b></span>
                <span class="co-name"><?= e($l['name']) ?><?= $l['variant_label'] ? '<small>' . e($l['variant_label']) . '</small>' : '' ?></span>
                <span class="co-line"><?= e(money($l['line_cents'] / 100)) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="sum-row"><span>Subtotal</span><span><?= e(money($cart['subtotal_cents'] / 100)) ?></span></p>
    <p class="sum-row"><span>Shipping</span><span><?= e(money($shipping_cents / 100)) ?></span></p>
    <p class="sum-row total"><span>Total</span><span><?= e(money($total / 100)) ?></span></p>
    <p><a href="<?= e(url('/cart')) ?>"><?= icon('left', 'i i-sm') ?> Edit cart</a></p>
</aside>
</div>
