<?php
$empty = $cart['items'] === [];
$nav = \App\Services\StoreFront::nav();
?>
<div class="page-head"><h1>Shopping cart <?php if (!$empty): ?><span class="muted">(<?= (int) $cart['count'] ?> <?= (int) $cart['count'] === 1 ? 'item' : 'items' ?>)</span><?php endif; ?></h1></div>
<div data-cart-page>
<?php if ($empty): ?>
    <div class="empty-state big">
        <?= icon('bag', 'i i-xl') ?>
        <h2>Your cart is empty</h2>
        <p class="muted">Looks like you haven't added anything yet.</p>
        <p><a class="s-btn lg" href="<?= e(url('/products')) ?>">Start shopping <?= icon('arrow') ?></a></p>
    </div>
    <?php if (!empty($suggest)): ?>
        <section class="sec"><div class="sec-head"><div><h2>You might like</h2></div></div>
            <div class="pgrid"><?php foreach ($suggest as $p) { require BASE_PATH . '/app/Views/partials/product_card.php'; } ?></div></section>
    <?php endif; ?>
<?php else: ?>
<form method="post" action="<?= e(url('/cart/update')) ?>" class="cart-page" data-cart-form>
    <?= csrf_field() ?>
    <?php /* Enter in a qty box must "Update", never hit the first Remove button. */ ?>
    <button class="sr-only" tabindex="-1" aria-hidden="true">Update quantities</button>
    <div class="cart-lines">
        <?php foreach ($cart['items'] as $l): $q = (int) ($l['available'] ? $l['qty'] : $l['requested']); ?>
            <article class="cl<?= $l['available'] ? '' : ' unavailable' ?>" data-item="<?= (int) $l['item_id'] ?>">
                <a class="cl-img" href="<?= e(url('/products/' . $l['slug'])) ?>"><?php if ($l['image']): ?><img src="<?= e(url($l['image'])) ?>" alt="" loading="lazy"><?php else: ?><span><?= icon('box', 'i i-lg') ?></span><?php endif; ?></a>
                <div class="cl-info">
                    <a class="cl-name" href="<?= e(url('/products/' . $l['slug'])) ?>"><?= e($l['name']) ?></a>
                    <?php if ($l['variant_label']): ?><span class="cl-variant"><?= e($l['variant_label']) ?></span><?php endif; ?>
                    <span class="cl-unit"><?= e(money($l['unit'])) ?><?php if ($l['on_sale']): ?> <s><?= e(money($l['retail'])) ?></s> <span class="tagpill tag-sale">Sale</span><?php endif; ?></span>
                    <?php if (!$l['available']): ?><span class="cl-warn">No longer available: remove it to continue.</span>
                    <?php elseif ($l['adjusted']): ?><span class="cl-warn">Only <?= (int) $l['stock'] ?> in stock: quantity lowered.</span><?php endif; ?>
                </div>
                <div class="qty qty-sm" data-qty>
                    <button type="button" data-qty-step="-1" aria-label="Decrease quantity of <?= e($l['name']) ?>"><?= icon('minus') ?></button>
                    <input type="number" name="qty[<?= (int) $l['item_id'] ?>]" value="<?= $q ?>" min="0" max="<?= max(1, min(99, (int) $l['stock'])) ?>" inputmode="numeric" aria-label="Quantity of <?= e($l['name']) ?>">
                    <button type="button" data-qty-step="1" aria-label="Increase quantity of <?= e($l['name']) ?>"><?= icon('plus') ?></button>
                </div>
                <strong class="cl-total"><?= e(money($l['line_cents'] / 100)) ?></strong>
                <button class="cl-remove" formaction="<?= e(url('/cart/remove')) ?>" name="item_id" value="<?= (int) $l['item_id'] ?>" aria-label="Remove <?= e($l['name']) ?>" data-remove><?= icon('trash') ?></button>
            </article>
        <?php endforeach; ?>
        <noscript><p><button class="s-btn ghost">Update quantities</button></p></noscript>
        <p class="cart-continue"><a href="<?= e(url('/products')) ?>"><?= icon('left', 'i i-sm') ?> Continue shopping</a></p>
    </div>
    <aside class="cart-summary">
        <h2>Order summary</h2>
        <p class="sum-row"><span>Subtotal</span><span><?= e(money($cart['subtotal_cents'] / 100)) ?></span></p>
        <p class="sum-row"><span>Shipping</span><span>Calculated at checkout</span></p>
        <p class="sum-row total"><span>Subtotal</span><span><?= e(money($cart['subtotal_cents'] / 100)) ?></span></p>
        <?php if ($cart['has_problem']): ?>
            <p class="cl-warn">Fix the items marked above to continue.</p>
            <span class="s-btn lg block disabled" aria-disabled="true">Checkout</span>
        <?php else: ?>
            <a class="s-btn lg block" href="<?= e(url('/checkout')) ?>">Checkout <?= icon('arrow') ?></a>
        <?php endif; ?>
        <ul class="sum-trust">
            <li><?= icon('shield', 'i i-sm') ?> Secure checkout<?= $nav['methods'] !== [] ? ': ' . e(implode(', ', $nav['methods'])) : '' ?></li>
            <li><?= icon('check', 'i i-sm') ?> No account needed: check out as a guest</li>
        </ul>
    </aside>
</form>
<?php endif; ?>
</div>
