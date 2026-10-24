<?php /** @var array $p  Plain card used where the storefront scripts are not loaded (the member portal's wishlist). */ ?>
<a class="card" href="<?= e(url('/products/' . $p['slug'])) ?>">
    <div class="card-img">
        <?php if ($p['image']): ?><img src="<?= e(url($p['image'])) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
        <?php else: ?><span class="muted">No image</span><?php endif; ?>
        <?php if ($p['sold_out']): ?><span class="badge draft tag">Sold out</span>
        <?php elseif ($p['on_sale']): ?><span class="badge sale tag">Sale</span><?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (!empty($p['brand_name'])): ?><small class="muted"><?= e($p['brand_name']) ?></small><?php endif; ?>
        <strong><?= e($p['name']) ?></strong>
        <span class="price">
            <?= $p['from'] ? 'From ' : '' ?><?= e(money($p['price'])) ?>
            <?php if ($p['on_sale']): ?><s class="muted"><?= e(money($p['retail'])) ?></s><?php endif; ?>
        </span>
    </div>
</a>
