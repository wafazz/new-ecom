<?php
$name = (string) config('app.name');
$nav = \App\Services\StoreFront::nav();
$me = \App\Core\Auth::user();
$section = function (string $title, string $href, ?string $sub = null): string {
    return '<div class="sec-head"><div><h2>' . e($title) . '</h2>' . ($sub ? '<p class="muted">' . e($sub) . '</p>' : '') . '</div><a class="sec-link" href="' . e($href) . '">View all ' . icon('arrow', 'i i-sm') . '</a></div>';
};
?>
<section class="hero">
    <div class="s-wrap hero-grid">
        <div class="hero-copy">
            <span class="hero-kicker"><?= icon('tag', 'i i-sm') ?> <?= $nav['hasSale'] ? 'Sale now on' : 'New season picks' ?></span>
            <h1>Find something<br>you'll love at <span class="grad"><?= e($name) ?></span></h1>
            <p>New arrivals, everyday favourites and great prices, delivered to your door. Save what you like, check out in a minute, and track every order.</p>
            <div class="hero-cta">
                <a class="s-btn lg" href="<?= e(url('/products')) ?>">Shop now <?= icon('arrow') ?></a>
                <?php if ($nav['hasSale']): ?><a class="s-btn lg ghost-light" href="<?= e(url('/products?sale=1')) ?>">See the sale</a>
                <?php else: ?><a class="s-btn lg ghost-light" href="<?= e(url('/track')) ?>">Track an order</a><?php endif; ?>
            </div>
            <ul class="hero-points">
                <li><?= icon('check', 'i i-sm') ?> Guest checkout</li>
                <li><?= icon('check', 'i i-sm') ?> Order tracking</li>
                <?php if ($nav['shipping'] !== null): ?><li><?= icon('check', 'i i-sm') ?> Flat shipping <?= e($nav['shipping']) ?></li><?php endif; ?>
            </ul>
        </div>
        <div class="hero-art" aria-hidden="true">
            <?php if ($heroProducts !== []): foreach ($heroProducts as $i => $hp): ?>
                <a class="hero-card hc<?= $i + 1 ?>" href="<?= e(url('/products/' . $hp['slug'])) ?>" tabindex="-1"><img src="<?= e(url($hp['image'])) ?>" alt="" loading="eager"><span><?= e($hp['name']) ?><b><?= e(money($hp['price'])) ?></b></span></a>
            <?php endforeach; else: ?>
                <span class="hero-blob b1"></span><span class="hero-blob b2"></span><span class="hero-blob b3"></span>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="usp s-wrap" aria-label="Why shop with us">
    <div><?= icon('shield', 'i i-lg') ?><div><strong>Secure checkout</strong><span>Your details stay private</span></div></div>
    <div><?= icon('pin', 'i i-lg') ?><div><strong>Track your order</strong><span>No account needed</span></div></div>
    <div><?= icon('truck', 'i i-lg') ?><div><strong><?= $nav['shipping'] !== null ? 'Flat ' . e($nav['shipping']) . ' shipping' : 'Delivered to your door' ?></strong><span>Courier delivery</span></div></div>
    <div><?= icon('heart', 'i i-lg') ?><div><strong>Save your favourites</strong><span>Create a free account</span></div></div>
</section>

<?php if ($products === []): ?>
    <div class="s-wrap"><div class="empty-state"><?= icon('box', 'i i-xl') ?><h2>The shelves are being stocked</h2><p class="muted">No products yet: check back soon.</p></div></div>
<?php else: ?>

<?php if ($tiles !== []): ?>
<section class="s-wrap sec">
    <div class="sec-head"><div><h2>Shop by category</h2><p class="muted">Start with what you need</p></div><a class="sec-link" href="<?= e(url('/products')) ?>">Everything <?= icon('arrow', 'i i-sm') ?></a></div>
    <div class="tiles-grid">
        <?php foreach ($tiles as $i => $t): ?>
            <a class="cat-tile t<?= $i % 6 ?>" href="<?= e(url('/products?category=' . $t['slug'])) ?>">
                <?php if ($t['image']): ?><img src="<?= e(url($t['image'])) ?>" alt="" loading="lazy"><?php endif; ?>
                <span class="cat-tile-body"><strong><?= e($t['name']) ?></strong><small><?= (int) $t['n'] ?> <?= (int) $t['n'] === 1 ? 'product' : 'products' ?></small></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="s-wrap sec">
    <?= $section('New arrivals', url('/products'), 'Fresh in this week') ?>
    <div class="pgrid"><?php foreach ($products as $p) { require BASE_PATH . '/app/Views/partials/product_card.php'; } ?></div>
</section>

<?php if ($sale !== []): ?>
<section class="sale-band">
    <div class="s-wrap sec">
        <?= $section('On sale', url('/products?sale=1'), 'Limited-time prices') ?>
        <div class="pgrid"><?php foreach (array_slice($sale, 0, 4) as $p) { require BASE_PATH . '/app/Views/partials/product_card.php'; } ?></div>
    </div>
</section>
<?php endif; ?>

<?php if ($popular !== []): ?>
<section class="s-wrap sec">
    <div class="sec-head"><div><h2>Popular right now</h2><p class="muted">What other customers are buying</p></div></div>
    <div class="pgrid"><?php foreach ($popular as $p) { require BASE_PATH . '/app/Views/partials/product_card.php'; } ?></div>
</section>
<?php endif; ?>

<?php if ($brands !== []): ?>
<section class="s-wrap sec">
    <div class="sec-head"><div><h2>Shop by brand</h2></div></div>
    <div class="brand-strip">
        <?php foreach ($brands as $b): ?>
            <a href="<?= e(url('/products?brand=' . $b['slug'])) ?>" class="brand-chip" title="<?= e($b['name']) ?>">
                <?php if (!empty($b['logo'])): ?><img src="<?= e(url($b['logo'])) ?>" alt="<?= e($b['name']) ?>" loading="lazy"><?php else: ?><span><?= e($b['name']) ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if (!$me): ?>
<section class="s-wrap sec">
    <div class="cta-band">
        <div><h2>Make it yours</h2><p>Create a free account to save favourites, speed through checkout and follow your orders.</p></div>
        <div class="cta-actions"><a class="s-btn lg" href="<?= e(url('/register')) ?>">Create account</a><a class="s-btn lg ghost-light" href="<?= e(url('/login')) ?>">Log in</a></div>
    </div>
</section>
<?php endif; ?>
<?php endif; ?>
