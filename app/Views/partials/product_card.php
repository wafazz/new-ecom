<?php
/** @var array $p  Storefront product card (needs shop.js for the quick-add and heart buttons; both also work as plain forms). */
use App\Services\StoreFront;

$pid = (int) $p['id'];
$href = url('/products/' . $p['slug']);
$discount = ($p['on_sale'] && (float) $p['retail'] > 0) ? (int) round((1 - (float) $p['price'] / (float) $p['retail']) * 100) : 0;
$isNew = !empty($p['created_at']) && strtotime((string) $p['created_at']) > time() - 14 * 86400;
$hasVariants = (int) $p['variant_count'] > 0;
$wished = StoreFront::wished() ?? [];
$isWished = isset($wished[$pid]);
?>
<article class="pcard" data-product="<?= $pid ?>">
    <div class="pcard-media">
        <a href="<?= e($href) ?>" class="pcard-link" aria-label="<?= e($p['name']) ?>">
            <?php if ($p['image']): ?>
                <img class="pcard-img" src="<?= e(url($p['image'])) ?>" alt="" loading="lazy">
                <?php if (!empty($p['image2'])): ?><img class="pcard-img pcard-img2" src="<?= e(url($p['image2'])) ?>" alt="" loading="lazy"><?php endif; ?>
            <?php else: ?>
                <span class="pcard-ph" aria-hidden="true"><?= icon('box', 'i i-xl') ?></span>
            <?php endif; ?>
        </a>
        <div class="pcard-badges">
            <?php if ($p['sold_out']): ?><span class="tagpill tag-out">Sold out</span>
            <?php else: ?>
                <?php if (!empty($p['flash_percent'])): ?><span class="tagpill tag-flash">Flash</span><?php endif; ?>
                <?php if ($discount > 0): ?><span class="tagpill tag-sale">−<?= $discount ?>%</span><?php elseif ($p['on_sale']): ?><span class="tagpill tag-sale">Sale</span><?php endif; ?>
                <?php if ($isNew): ?><span class="tagpill tag-new">New</span><?php endif; ?>
            <?php endif; ?>
        </div>
        <form method="post" action="<?= e(url('/wishlist/toggle')) ?>" class="pcard-heart-form">
            <?= csrf_field() ?><input type="hidden" name="product_id" value="<?= $pid ?>">
            <button class="pcard-heart<?= $isWished ? ' on' : '' ?>" data-wish="<?= $pid ?>" aria-pressed="<?= $isWished ? 'true' : 'false' ?>" aria-label="<?= $isWished ? 'Remove from wishlist' : 'Save to wishlist' ?>: <?= e($p['name']) ?>"><?= icon('heart') ?></button>
        </form>
        <?php if (!$p['sold_out']): ?>
            <?php if ($hasVariants): ?>
                <a class="pcard-quick" href="<?= e($href) ?>">Choose options <?= icon('arrow') ?></a>
            <?php else: ?>
                <form method="post" action="<?= e(url('/cart/add')) ?>" class="pcard-quick-form">
                    <?= csrf_field() ?><input type="hidden" name="product_id" value="<?= $pid ?>"><input type="hidden" name="qty" value="1">
                    <button class="pcard-quick" data-add-to-cart><?= icon('bag') ?> Add to cart</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="pcard-body">
        <?php if (!empty($p['brand_name'])): ?><span class="pcard-brand"><?= e($p['brand_name']) ?></span><?php endif; ?>
        <h3 class="pcard-name"><a href="<?= e($href) ?>"><?= e($p['name']) ?></a></h3>
        <p class="pcard-price">
            <span class="now<?= $p['on_sale'] ? ' sale' : '' ?>"><?= $p['from'] ? '<small>From</small> ' : '' ?><?= e(money($p['price'])) ?></span>
            <?php if ($p['on_sale']): ?><s><?= e(money($p['retail'])) ?></s><?php endif; ?>
        </p>
    </div>
</article>
