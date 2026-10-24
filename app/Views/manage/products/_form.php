<?php
// Expects: $product, $categories, $brands, $isAdmin, $formAction; optional $productType ('single'|'variable') and $variantRows
$dt = fn(?string $v) => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
$type = old('product_type', $productType ?? 'single') === 'variable' ? 'variable' : 'single';
$vrows = ($variantRows ?? []) !== [] ? $variantRows : [['id' => 0, 'sku' => '', 'option' => '', 'value' => '', 'price' => '', 'stock' => 0]];
?>
<form method="post" action="<?= e(url($formAction)) ?>" class="card-form wide" id="product-form" data-product-form novalidate>
    <?= csrf_field() ?>
    <label>Name <input name="name" value="<?= old('name', $product['name']) ?>" required><?= field_error('name') ?></label>
    <fieldset class="ptype" data-product-type>
        <legend>Product type</legend>
        <label class="radio"><input type="radio" name="product_type" value="single" <?= $type === 'single' ? 'checked' : '' ?>> Single product</label>
        <label class="radio"><input type="radio" name="product_type" value="variable" <?= $type === 'variable' ? 'checked' : '' ?>> With variations</label>
        <small class="muted" data-drop-note hidden>Saving as a single product removes this product's existing variations.</small>
    </fieldset>
    <div class="grid2">
        <label data-single-only>SKU <input name="sku" value="<?= old('sku', (string) $product['sku']) ?>"><?= field_error('sku') ?></label>
        <label>URL slug (auto if blank) <input name="slug" value="<?= old('slug', $product['slug']) ?>"><?= field_error('slug') ?></label>
    </div>
    <div class="grid2">
        <label>Category
            <select name="category_id">
                <option value="">— none —</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= old('category_id', (string) $product['category_id']) === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['label']) ?></option>
                <?php endforeach; ?>
            </select><?= field_error('category_id') ?>
        </label>
        <label>Brand
            <select name="brand_id">
                <option value="">— none —</option>
                <?php foreach ($brands as $b): ?>
                    <option value="<?= (int) $b['id'] ?>" <?= old('brand_id', (string) ($product['brand_id'] ?? '')) === (string) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                <?php endforeach; ?>
            </select><?= field_error('brand_id') ?>
        </label>
    </div>
    <div class="grid2">
        <label>Status
            <select name="status">
                <?php foreach (['draft' => 'Draft (hidden)', 'active' => 'Active (visible)'] as $k => $label): ?>
                    <option value="<?= $k ?>" <?= old('status', $product['status']) === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <?php
    $imgs = array_map(fn($i) => ['id' => (int) $i['id'], 'url' => url($i['path'])], $images ?? []);
    $maxImages = \App\Services\ProductImageService::MAX;
    ?>
    <fieldset class="images-field" data-images data-max="<?= $maxImages ?>" data-existing="<?= e(json_encode($imgs, JSON_THROW_ON_ERROR)) ?>">
        <legend>Images <small class="muted" data-img-count></small></legend>
        <div class="img-drop" data-img-drop tabindex="0" role="button" aria-label="Add images: drop files here or press Enter to browse" hidden>
            <div class="img-tiles" data-img-tiles></div>
            <p class="img-hint" data-img-hint>Drag &amp; drop images here, or click to browse.</p>
        </div>
        <input type="file" data-img-picker accept="image/jpeg,image/png,image/webp" multiple hidden>
        <input type="file" name="images[]" data-img-input accept="image/jpeg,image/png,image/webp" multiple hidden>
        <noscript><label>Add images <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple></label></noscript>
        <small class="muted">JPG / PNG / WebP, up to <?= $maxImages ?> images. Drag the thumbnails to reorder: the first one is the main image. Large photos are resized in your browser.</small>
        <?= field_error('images') ?>
    </fieldset>

    <?php
    $descRaw = $_SESSION['_old']['description'] ?? $product['description'] ?? '';
    $descHtml = \App\Services\RichText::clean(is_scalar($descRaw) ? (string) $descRaw : ''); // plain text from before the editor becomes paragraphs
    ?>
    <div class="rt-field" data-richtext>
        <label for="description">Description</label>
        <div class="rt-editor" data-rt-editor hidden></div>
        <textarea name="description" id="description" rows="6" data-rt-source><?= e($descHtml) ?></textarea>
        <?= field_error('description') ?>
    </div>

    <fieldset>
        <legend>Pricing (<?= e(config('app.currency')) ?>)</legend>
        <div class="grid2">
            <?php if ($isAdmin): ?>
                <label>Base price (your cost) <input name="base_price" inputmode="decimal" value="<?= old('base_price', (string) $product['base_price']) ?>"><?= field_error('base_price') ?></label>
            <?php endif; ?>
            <label>Company base price (floor) <input name="company_base_price" inputmode="decimal" value="<?= old('company_base_price', (string) $product['company_base_price']) ?>"><?= field_error('company_base_price') ?></label>
            <label>Retail price <input name="retail_price" inputmode="decimal" value="<?= old('retail_price', (string) $product['retail_price']) ?>"><?= field_error('retail_price') ?></label>
            <label>Discount price (optional) <input name="discount_price" inputmode="decimal" value="<?= old('discount_price', (string) $product['discount_price']) ?>"><?= field_error('discount_price') ?></label>
            <label>Discount starts (optional) <input type="datetime-local" name="discount_start" value="<?= old('discount_start', $dt($product['discount_start'])) ?>"><?= field_error('discount_start') ?></label>
            <label>Discount ends (optional) <input type="datetime-local" name="discount_end" value="<?= old('discount_end', $dt($product['discount_end'])) ?>"><?= field_error('discount_end') ?></label>
        </div>
        <small class="muted">Rule: retail ≥ company base ≥ base; discount ≤ retail.</small>
        <p class="margin-preview" id="margin-preview" aria-live="polite"></p>
    </fieldset>

    <?php if ($isAdmin): ?>
        <label>Affiliate commission override (% of margin, blank = default)
            <input name="commission_rate" inputmode="decimal" value="<?= old('commission_rate', (string) ($product['commission_rate'] ?? '')) ?>"><?= field_error('commission_rate') ?></label>
    <?php endif; ?>

    <?php if (isset($stockTotal)): ?>
        <p class="stock-note">Stock: <strong><?= number_format((int) $stockTotal) ?></strong> in stock.
            <?php if (\App\Core\Auth::can('stock.manage')): ?><a href="<?= e(url(\App\Core\Auth::HOME[\App\Core\Auth::user()['role']] . '/stock?product=' . (int) $product['id'])) ?>">Change it in Stock Control →</a>
            <?php else: ?><span class="muted">Stock is changed in Stock Control.</span><?php endif; ?></p>
    <?php else: ?>
        <p class="stock-note muted">New products start with 0 stock. Add stock afterwards in <strong>Stock Control</strong>.</p>
    <?php endif; ?>

    <fieldset class="variations" data-variations data-count="<?= count($vrows) ?>" <?= $type === 'variable' ? '' : 'data-start-hidden' ?>>
        <legend>Variations</legend>
        <p class="muted">Each row is one variation (its stock is set in Stock Control). Leave the price blank to use the product's retail price. Use the same option name (e.g. Size) on several rows to group them.</p>
        <div class="var-head" aria-hidden="true"><span>SKU</span><span>Variant option</span><span>Variant value</span><span>Variant price (optional)</span><span></span></div>
        <div class="var-rows" data-var-rows>
            <?php foreach ($vrows as $i => $r): ?>
                <div class="var-row" data-var-row>
                    <input type="hidden" name="variants[<?= $i ?>][id]" value="<?= (int) $r['id'] ?>">
                    <input name="variants[<?= $i ?>][sku]" value="<?= e((string) $r['sku']) ?>" placeholder="SKU (auto if blank)" aria-label="SKU" maxlength="60">
                    <input name="variants[<?= $i ?>][option]" value="<?= e((string) $r['option']) ?>" placeholder="e.g. Size" aria-label="Variant option" maxlength="50">
                    <input name="variants[<?= $i ?>][value]" value="<?= e((string) $r['value']) ?>" placeholder="e.g. Small" aria-label="Variant value" maxlength="50">
                    <input name="variants[<?= $i ?>][price]" value="<?= e((string) $r['price']) ?>" placeholder="Same as product" inputmode="decimal" aria-label="Variant price (optional)">
                    <button type="button" class="icon-btn" data-var-remove aria-label="Remove this variation" title="Remove">×</button>
                </div>
            <?php endforeach; ?>
        </div>
        <template data-var-template>
            <div class="var-row" data-var-row>
                <input type="hidden" name="variants[__i__][id]" value="0">
                <input name="variants[__i__][sku]" placeholder="SKU (auto if blank)" aria-label="SKU" maxlength="60">
                <input name="variants[__i__][option]" placeholder="e.g. Size" aria-label="Variant option" maxlength="50">
                <input name="variants[__i__][value]" placeholder="e.g. Small" aria-label="Variant value" maxlength="50">
                <input name="variants[__i__][price]" placeholder="Same as product" inputmode="decimal" aria-label="Variant price (optional)">
                <button type="button" class="icon-btn" data-var-remove aria-label="Remove this variation" title="Remove">×</button>
            </div>
        </template>
        <p><button type="button" class="btn secondary" data-var-add>+ Add more</button></p>
        <?= field_error('form') ?>
    </fieldset>

    <?php if (empty($inModal)): ?><button class="btn">Save product</button><?php endif; ?>
</form>
