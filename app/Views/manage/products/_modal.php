<?php
/** Expects: $isNew, $product, $categories, $isAdmin, $base and, when editing, $images, $variantRows, $productType. Rendered without a layout. */
$inModal = true;
$pid = $isNew ? 0 : (int) $product['id'];
$formAction = $isNew ? $base . '/products' : $base . "/products/{$pid}";
?>
<div class="modal-inner" data-product-id="<?= $pid ?>" data-new="<?= $isNew ? '1' : '0' ?>">
    <header class="modal-head">
        <h2 id="modal-title"><?= $isNew ? 'Add product' : e($product['name']) ?></h2>
        <button type="button" class="icon-btn" data-modal-close aria-label="Close"><svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
    </header>
    <div class="modal-body">
        <div class="alert bad" id="modal-alert" role="alert" hidden></div>
        <?php if ($isNew): ?><p class="muted">Fill in the details and drop your images in. Everything saves together.</p><?php endif; ?>
        <div class="tab-panel" data-panel="details"><?php require __DIR__ . '/_form.php'; ?></div>
    </div>
    <footer class="modal-foot" data-foot>
        <?php if (!$isNew): ?>
            <form method="post" action="<?= e(url($base . "/products/{$pid}/delete")) ?>" data-confirm="Delete “<?= e($product['name']) ?>”? It disappears from the shop; past orders keep their details." data-confirm-button="Yes, delete" data-confirm-danger="1">
                <?= csrf_field() ?><button class="btn danger-btn" type="submit">Delete product</button>
            </form>
        <?php endif; ?>
        <span class="spacer"></span>
        <button type="button" class="btn secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn" form="product-form"><?= $isNew ? 'Create product' : 'Save changes' ?></button>
    </footer>
</div>
