<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Wishlist</h1>
            <p class="muted">Products you saved for later. Remove them when you no longer want them.</p>
        </div>
        <span class="msg-pill"><?= count($products) ?> saved</span>
    </header>

    <?php if ($products === []): ?>
        <section class="msg-card"><p class="msg-empty">Nothing saved yet. Use "Save to wishlist" on a <a href="<?= e(url('/products')) ?>">product page</a>.</p></section>
    <?php else: ?>
        <section class="msg-card">
            <div class="grid-cards">
                <?php foreach ($products as $p): ?>
                    <div>
                        <?php require BASE_PATH . '/app/Views/partials/product_card_simple.php'; ?>
                        <form method="post" action="<?= e(url('/wishlist/toggle')) ?>" class="wish" data-busy-form>
                            <?= csrf_field() ?><input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="next" value="wishlist">
                            <button class="msg-link danger">Remove</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
