<?php
use App\Services\RichText;
use App\Services\StoreFront;
use App\Services\Payment\Payments;

$pid = (int) $product['id'];
$hasVariants = $variants !== [];
$discount = ($base['on_sale'] && (float) $base['retail'] > 0) ? (int) round((1 - (float) $base['price'] / (float) $base['retail']) * 100) : 0;
$totalStock = $hasVariants ? (int) array_sum(array_column($variants, 'stock')) : (\App\Services\DigitalService::isDigital($product) ? \App\Services\DigitalService::stock($product) : (int) $product['stock']);
$LOW = \App\Models\Product::LOW_STOCK;
$desc = RichText::clean($product['description']);
$shots = array_map(fn($i) => url($i['path']), $images);
// Variations grouped under their option name (Size, Color…), in the order they were entered.
$groups = [];
foreach ($variants as $v) { $groups[$v['option'] !== '' ? $v['option'] : 'Option'][] = $v; }
$methods = array_values(array_map(fn($m) => $m->label(), Payments::checkoutMethods()));
$wishInit = $wish === true;
?>
<nav class="crumbs" aria-label="Breadcrumb">
    <a href="<?= e(url('/')) ?>">Home</a><?= icon('right', 'i i-sm') ?>
    <a href="<?= e(url('/products')) ?>">Shop</a>
    <?php if (!empty($product['parent_slug'])): ?><?= icon('right', 'i i-sm') ?><a href="<?= e(url('/products?category=' . $product['parent_slug'])) ?>"><?= e($product['parent_name']) ?></a><?php endif; ?>
    <?php if ($product['category_name']): ?><?= icon('right', 'i i-sm') ?><a href="<?= e(url('/products?category=' . $product['category_slug'])) ?>"><?= e($product['category_name']) ?></a><?php endif; ?>
    <?= icon('right', 'i i-sm') ?><span><?= e($product['name']) ?></span>
</nav>

<div class="pdp" data-pdp data-product="<?= $pid ?>" data-has-variants="<?= $hasVariants ? '1' : '0' ?>" data-stock="<?= $totalStock ?>" data-low="<?= (int) $LOW ?>" data-sku="<?= e((string) $product['sku']) ?>">
    <div class="pdp-gallery" data-gallery data-images="<?= e(json_encode($shots, JSON_UNESCAPED_SLASHES)) ?>">
        <?php if (count($shots) > 1): ?>
            <div class="pdp-thumbs" role="tablist" aria-label="Product photos">
                <?php foreach ($shots as $i => $src): ?>
                    <button type="button" class="pdp-thumb<?= $i === 0 ? ' on' : '' ?>" data-thumb="<?= $i ?>" role="tab" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" aria-label="Photo <?= $i + 1 ?> of <?= count($shots) ?>"><img src="<?= e($src) ?>" alt="" loading="lazy"></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="pdp-stage" data-stage>
            <?php if ($shots === []): ?>
                <div class="pdp-noimg"><?= icon('box', 'i i-xl') ?><span>No photo yet</span></div>
            <?php else: ?>
                <img id="main-img" class="pdp-main" src="<?= e($shots[0]) ?>" alt="<?= e($product['name']) ?>" width="900" height="900" fetchpriority="high">
                <button type="button" class="pdp-zoom" data-lightbox-open aria-label="View larger"><?= icon('zoom') ?></button>
                <?php if (count($shots) > 1): ?>
                    <button type="button" class="pdp-arrow prev" data-step="-1" aria-label="Previous photo"><?= icon('left') ?></button>
                    <button type="button" class="pdp-arrow next" data-step="1" aria-label="Next photo"><?= icon('right') ?></button>
                <?php endif; ?>
            <?php endif; ?>
            <div class="pcard-badges">
                <?php if (!$inStock): ?><span class="tagpill tag-out">Sold out</span>
                <?php elseif ($discount > 0): ?><span class="tagpill tag-sale">−<?= $discount ?>%</span><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="pdp-info">
        <?php if (!empty($product['brand_name'])): ?>
            <a class="pdp-brand" href="<?= e(url('/products?brand=' . $product['brand_slug'])) ?>">
                <?php if (!empty($product['brand_logo'])): ?><img src="<?= e(url($product['brand_logo'])) ?>" alt="" class="brand-logo"><?php endif; ?><?= e($product['brand_name']) ?>
            </a>
        <?php endif; ?>
        <h1 class="pdp-title"><?= e($product['name']) ?></h1>
        <?php if ($reviewSummary['count'] > 0): ?>
            <a class="pdp-rating" href="#reviews"><span class="rv-stars" aria-hidden="true"><?= e(str_repeat('★', (int) round((float) $reviewSummary['avg'])) . str_repeat('☆', 5 - (int) round((float) $reviewSummary['avg']))) ?></span> <?= e(number_format((float) $reviewSummary['avg'], 1)) ?> (<?= (int) $reviewSummary['count'] ?> review<?= $reviewSummary['count'] === 1 ? '' : 's' ?>)</a>
        <?php endif; ?>

        <p class="pdp-price" id="price" aria-live="polite">
            <span class="now<?= $base['on_sale'] ? ' sale' : '' ?>" id="price-now"><?= e(money($base['price'])) ?></span>
            <s id="price-was" <?= $base['on_sale'] ? '' : 'hidden' ?>><?= e(money($base['retail'])) ?></s>
            <span class="save-badge" id="price-save" <?= $discount > 0 ? '' : 'hidden' ?>>Save <?= $discount ?>%</span>
        </p>
        <?php if (!empty($base['flash'])): ?>
            <div class="flash-pdp" data-flash-pdp>
                <span class="flash-kicker"><?= icon('tag', 'i i-sm') ?> Flash sale: <?= e($base['flash']['name']) ?></span>
                <?php $ends = $base['flash']['ends_at']; require BASE_PATH . '/app/Views/partials/flash_timer.php'; ?>
            </div>
        <?php endif; ?>
        <?php if ($hasVariants): ?><p class="pdp-from muted" id="price-hint">Price shown for the cheapest option: choose one to see its price.</p><?php endif; ?>

        <p class="pdp-stock" id="stock-note" data-state="<?= !$inStock ? 'out' : (!$hasVariants && $totalStock <= $LOW ? 'low' : 'ok') ?>">
            <?php if (!$inStock): ?>Sold out
            <?php elseif (!$hasVariants && $totalStock <= $LOW): ?>Only <?= $totalStock ?> left: order soon
            <?php else: ?>In stock<?php endif; ?>
        </p>

        <form method="post" action="<?= e(url('/cart/add')) ?>" class="pdp-form" data-pdp-form novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="product_id" value="<?= $pid ?>">
            <?php if ($hasVariants): ?>
                <div class="opt-groups" data-variants>
                    <?php foreach ($groups as $gname => $items): ?>
                        <fieldset class="opt-group">
                            <legend><?= e($gname) ?> <span class="chosen" data-chosen hidden></span></legend>
                            <div class="pills">
                                <?php foreach ($items as $v): ?>
                                    <button type="button" class="pill<?= $v['stock'] < 1 ? ' out' : '' ?>" data-variant="<?= (int) $v['id'] ?>" data-label="<?= e($v['label']) ?>" data-price="<?= e(money($v['price'])) ?>" data-retail="<?= e(money($v['retail'])) ?>" data-sale="<?= $v['on_sale'] ? '1' : '0' ?>" data-discount="<?= ($v['on_sale'] && (float) $v['retail'] > 0) ? (int) round((1 - (float) $v['price'] / (float) $v['retail']) * 100) : 0 ?>" data-stock="<?= (int) $v['stock'] ?>" data-sku="<?= e((string) ($v['sku'] ?? '')) ?>" aria-pressed="false"<?= $v['stock'] < 1 ? ' aria-disabled="true" title="Sold out"' : '' ?>><?= e($v['label']) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    <?php endforeach; ?>
                    <p class="pdp-choose" data-choose-msg hidden role="alert">Please choose an option first.</p>
                </div>
                <noscript><label>Option <select name="variant_id" required><option value="">Select…</option><?php foreach ($variants as $v): ?><option value="<?= (int) $v['id'] ?>" <?= $v['stock'] < 1 ? 'disabled' : '' ?>><?= e($v['label']) ?><?= $v['stock'] < 1 ? ' (sold out)' : '' ?></option><?php endforeach; ?></select></label></noscript>
            <?php endif; ?>

            <?php if ($inStock): ?>
                <div class="pdp-actions" data-actions>
                    <div class="qty" data-qty>
                        <button type="button" data-qty-step="-1" aria-label="Decrease quantity"><?= icon('minus') ?></button>
                        <input type="number" name="qty" value="1" min="1" max="<?= min(99, max(1, $hasVariants ? 99 : $totalStock)) ?>" inputmode="numeric" aria-label="Quantity">
                        <button type="button" data-qty-step="1" aria-label="Increase quantity"><?= icon('plus') ?></button>
                    </div>
                    <button class="s-btn lg grow" data-pdp-add><?= icon('bag') ?> Add to cart</button>
                    <button type="button" class="s-icon-btn heart-btn<?= $wishInit ? ' on' : '' ?>" data-wish="<?= $pid ?>" aria-pressed="<?= $wishInit ? 'true' : 'false' ?>" aria-label="<?= $wishInit ? 'Remove from wishlist' : 'Save to wishlist' ?>"><?= icon('heart') ?></button>
                </div>
                <button type="button" class="s-btn ghost block buy-now" data-buy-now>Buy it now</button>
            <?php else: ?>
                <div class="pdp-actions"><button type="button" class="s-btn lg grow" disabled>Sold out</button>
                    <button type="button" class="s-icon-btn heart-btn<?= $wishInit ? ' on' : '' ?>" data-wish="<?= $pid ?>" aria-pressed="<?= $wishInit ? 'true' : 'false' ?>" aria-label="Save to wishlist"><?= icon('heart') ?></button></div>
            <?php endif; ?>
        </form>

        <?php if (!empty($affiliateLink)): ?>
        <div class="aff-copy" style="margin:.9rem 0;padding:.75rem .9rem;border:1px dashed var(--border-strong, #c8cdd6);border-radius:12px">
            <small class="muted">Your affiliate link for this product. Share it, and you earn commission on any sale made through it.</small>
            <div style="display:flex;gap:.5rem;margin-top:.4rem;flex-wrap:wrap">
                <input readonly value="<?= e($affiliateLink) ?>" onfocus="this.select()" aria-label="Your affiliate link for this product" style="flex:1;min-width:200px">
                <button type="button" class="s-btn secondary" data-copy="<?= e($affiliateLink) ?>">Copy link</button>
            </div>
        </div>
        <?php endif; ?>

        <ul class="pdp-trust">
            <li><?= icon('truck') ?> <span>Shipping by weight and zone, worked out at checkout</span></li>
            <li><?= icon('shield') ?> <span>Secure checkout<?= $methods !== [] ? ': ' . e(implode(', ', $methods)) : '' ?></span></li>
            <li><?= icon('pin') ?> <span>Track your order any time</span></li>
        </ul>

        <div class="tabs" data-tabs>
            <div class="tab-list" role="tablist">
                <button type="button" role="tab" aria-selected="true" data-tab="desc">Description</button>
                <button type="button" role="tab" aria-selected="false" data-tab="details">Details</button>
                <button type="button" role="tab" aria-selected="false" data-tab="shipping">Shipping &amp; payment</button>
            </div>
            <div class="tab-panel" data-panel="desc"><?php if ($desc !== ''): ?><div class="desc"><?= $desc ?></div><?php else: ?><p class="muted">No description yet.</p><?php endif; ?></div>
            <div class="tab-panel" data-panel="details" hidden>
                <dl class="spec">
                    <div><dt>SKU</dt><dd id="spec-sku"><?= e($hasVariants ? '—' : ((string) $product['sku'] ?: '—')) ?></dd></div>
                    <?php if ($product['category_name']): ?><div><dt>Category</dt><dd><a href="<?= e(url('/products?category=' . $product['category_slug'])) ?>"><?= e($product['category_name']) ?></a></dd></div><?php endif; ?>
                    <?php if (!empty($product['brand_name'])): ?><div><dt>Brand</dt><dd><a href="<?= e(url('/products?brand=' . $product['brand_slug'])) ?>"><?= e($product['brand_name']) ?></a></dd></div><?php endif; ?>
                    <div><dt>Availability</dt><dd><?= $inStock ? 'In stock' : 'Sold out' ?></dd></div>
                </dl>
            </div>
            <div class="tab-panel" data-panel="shipping" hidden>
                <p>Shipping is worked out at checkout from the weight of your items and your state. Sabah, Sarawak and Labuan have their own rates. We deliver within Malaysia.</p>
                <?php if ($methods !== []): ?><p>You can pay by <?= e(implode(', ', $methods)) ?>.</p><?php endif; ?>
                <p>After you order, use <a href="<?= e(url('/track')) ?>">Track order</a> with your order number and email to see where it is.</p>
            </div>
        </div>
    </div>
</div>

<div class="s-wrap">
    <?php require BASE_PATH . '/app/Views/partials/product_reviews.php'; ?>
</div>

<?php if ($related !== []): ?>
<section class="sec">
    <div class="sec-head"><div><h2>You may also like</h2></div><a class="sec-link" href="<?= e(url('/products' . ($product['category_slug'] ? '?category=' . $product['category_slug'] : ''))) ?>">More like this <?= icon('arrow', 'i i-sm') ?></a></div>
    <div class="pgrid"><?php foreach ($related as $p) { require BASE_PATH . '/app/Views/partials/product_card.php'; } ?></div>
</section>
<?php endif; ?>

<!-- sticky add-to-cart for phones -->
<div class="pdp-sticky" data-sticky hidden>
    <div><strong><?= e($product['name']) ?></strong><span id="sticky-price"><?= e(money($base['price'])) ?></span></div>
    <button type="button" class="s-btn" data-sticky-add<?= $inStock ? '' : ' disabled' ?>><?= $inStock ? 'Add to cart' : 'Sold out' ?></button>
</div>

<dialog class="lightbox" id="lightbox" aria-label="Product photos">
    <button type="button" class="lb-close s-icon-btn" data-lb-close aria-label="Close"><?= icon('x') ?></button>
    <button type="button" class="lb-nav prev" data-lb-step="-1" aria-label="Previous photo"><?= icon('left') ?></button>
    <img id="lb-img" alt="">
    <button type="button" class="lb-nav next" data-lb-step="1" aria-label="Next photo"><?= icon('right') ?></button>
    <p class="lb-count" id="lb-count"></p>
</dialog>

<script type="application/ld+json"><?= json_encode(array_filter([
    '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $product['name'],
    'image' => $shots ?: null, 'description' => $desc !== '' ? mb_substr(trim(strip_tags($desc)), 0, 300) : null,
    'sku' => $product['sku'] ?: null,
    'aggregateRating' => $reviewSummary['count'] > 0 ? ['@type' => 'AggregateRating', 'ratingValue' => (string) $reviewSummary['avg'], 'reviewCount' => (string) $reviewSummary['count'], 'bestRating' => '5', 'worstRating' => '1'] : null,
    'brand' => !empty($product['brand_name']) ? ['@type' => 'Brand', 'name' => $product['brand_name']] : null,
    'offers' => ['@type' => 'Offer', 'url' => url('/products/' . $product['slug']), 'priceCurrency' => (string) config('app.currency'), 'price' => (string) $base['price'],
        'availability' => 'https://schema.org/' . ($inStock ? 'InStock' : 'OutOfStock')],
]), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
