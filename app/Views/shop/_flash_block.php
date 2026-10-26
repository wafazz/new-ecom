<?php
/** Storefront flash sale block. Expects $flashSale = ['name', 'ends_at', 'products' => card rows]; $preview true in the admin editor. */
$sale_name = (string) $flashSale['name'];
$ends = (string) $flashSale['ends_at'];
?>
<section class="s-wrap sec flash-sec" aria-label="Flash sale: <?= e($sale_name) ?>" <?= !empty($preview) ? 'data-flash-block-preview' : '' ?>>
    <div class="sec-head flash-head">
        <div>
            <span class="flash-kicker"><?= icon('tag', 'i i-sm') ?> Flash sale</span>
            <h2><?= e($sale_name) ?></h2>
        </div>
        <?php require BASE_PATH . '/app/Views/partials/flash_timer.php'; ?>
    </div>
    <div class="flash-carousel" data-flash-carousel>
        <button type="button" class="flash-nav prev" data-flash-step="-1" aria-label="Previous products"><?= icon('left') ?></button>
        <div class="flash-track" data-flash-track>
            <?php foreach ($flashSale['products'] as $p) { require BASE_PATH . '/app/Views/partials/product_card.php'; } ?>
        </div>
        <button type="button" class="flash-nav next" data-flash-step="1" aria-label="Next products"><?= icon('right') ?></button>
    </div>
</section>
