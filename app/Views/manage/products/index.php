<div class="row-between">
    <h1>Products</h1>
    <a class="btn" href="<?= e(url($base . '/products/create')) ?>" data-open-product="new">Add product</a>
</div>

<form method="get" class="prod-filters" id="prod-filters" action="<?= e(url($base . '/products')) ?>">
    <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search name or SKU…" aria-label="Search products" autocomplete="off">
    <select name="category" aria-label="Category">
        <option value="">All categories</option>
        <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= (int) $filters['category'] === (int) $c['id'] ? 'selected' : '' ?>><?= $c['depth'] ? '— ' : '' ?><?= e($c['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="brand" aria-label="Brand">
        <option value="">All brands</option>
        <?php foreach ($brands as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= (int) $filters['brand'] === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="sort" aria-label="Sort">
        <?php foreach ($sorts as $k => $label): ?><option value="<?= e($k) ?>" <?= $filters['sort'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
    <input type="hidden" name="stock" value="<?= e($filters['stock']) ?>">
    <noscript><button class="btn secondary">Apply</button></noscript>
</form>

<div id="prod-results" data-base="<?= e($base) ?>" data-endpoint="<?= e(url($base . '/products')) ?>" data-admin="<?= $isAdmin ? '1' : '0' ?>">
    <?php require __DIR__ . '/_list.php'; ?>
</div>

<dialog id="product-modal" class="modal" aria-labelledby="modal-title"></dialog>
