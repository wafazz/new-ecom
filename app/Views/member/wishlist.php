<h1>Wishlist</h1>
<?php if ($products === []): ?>
    <p class="muted">Nothing saved yet. Use "Save to wishlist" on a <a href="<?= e(url('/products')) ?>">product page</a>.</p>
<?php else: ?>
    <div class="grid-cards">
        <?php foreach ($products as $p): ?>
            <div>
                <?php require BASE_PATH . '/app/Views/partials/product_card_simple.php'; ?>
                <form method="post" action="<?= e(url('/wishlist/toggle')) ?>" class="wish">
                    <?= csrf_field() ?><input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="next" value="wishlist">
                    <button class="link">Remove</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
