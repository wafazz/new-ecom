<?php
use App\Models\Product;
$f = $filters;
$activeCount = ($f['category'] !== '' ? 1 : 0) + ($f['brand'] !== '' ? 1 : 0) + (($f['min'] !== null || $f['max'] !== null) ? 1 : 0) + ($f['sale'] === '1' ? 1 : 0) + ($f['instock'] === '1' ? 1 : 0);
?>
<nav class="crumbs" aria-label="Breadcrumb">
    <a href="<?= e(url('/')) ?>">Home</a><?= icon('right', 'i i-sm') ?>
    <?php if ($f['q'] !== ''): ?><span>Search</span><?php elseif ($catName): ?><a href="<?= e(url('/products')) ?>">Shop</a><?= icon('right', 'i i-sm') ?><span><?= e($catName) ?></span><?php else: ?><span>Shop</span><?php endif; ?>
</nav>
<div class="cat-title">
    <h1><?= e($heading) ?></h1>
</div>

<div class="cat-layout" data-catalog data-endpoint="<?= e(url('/products')) ?>">
    <aside class="sfilters" id="filters" aria-label="Filters">
        <div class="filters-head"><strong><?= icon('filter') ?> Filters</strong><button type="button" class="s-icon-btn" data-filters-close aria-label="Close filters"><?= icon('x') ?></button></div>
        <form id="filter-form" method="get" action="<?= e(url('/products')) ?>">
            <input type="hidden" name="q" value="<?= e($f['q']) ?>">

            <fieldset class="fgroup">
                <legend>Category</legend>
                <label class="opt"><input type="radio" name="category" value="" <?= $f['category'] === '' ? 'checked' : '' ?>><span>All categories</span></label>
                <?php foreach ($categories as $c): ?>
                    <label class="opt"><input type="radio" name="category" value="<?= e($c['slug']) ?>" <?= $f['category'] === $c['slug'] ? 'checked' : '' ?>><span><?= e($c['name']) ?></span><em><?= (int) $c['n'] ?></em></label>
                    <?php foreach ($c['children'] as $k): ?>
                        <label class="opt sub"><input type="radio" name="category" value="<?= e($k['slug']) ?>" <?= $f['category'] === $k['slug'] ? 'checked' : '' ?>><span><?= e($k['name']) ?></span><em><?= (int) $k['n'] ?></em></label>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </fieldset>

            <?php if ($brands !== []): ?>
            <fieldset class="fgroup">
                <legend>Brand</legend>
                <label class="opt"><input type="radio" name="brand" value="" <?= $f['brand'] === '' ? 'checked' : '' ?>><span>All brands</span></label>
                <?php foreach ($brands as $b): ?>
                    <label class="opt"><input type="radio" name="brand" value="<?= e($b['slug']) ?>" <?= $f['brand'] === $b['slug'] ? 'checked' : '' ?>><span><?= e($b['name']) ?></span><em><?= (int) $b['n'] ?></em></label>
                <?php endforeach; ?>
            </fieldset>
            <?php endif; ?>

            <fieldset class="fgroup">
                <legend>Price (<?= e((string) config('app.currency')) ?>)</legend>
                <div class="price-range">
                    <label><span class="sr-only">Minimum price</span><input type="number" name="min" min="0" step="1" inputmode="decimal" placeholder="Min" value="<?= e((string) ($f['min'] ?? '')) ?>"></label>
                    <span aria-hidden="true">–</span>
                    <label><span class="sr-only">Maximum price</span><input type="number" name="max" min="0" step="1" inputmode="decimal" placeholder="Max" value="<?= e((string) ($f['max'] ?? '')) ?>"></label>
                </div>
            </fieldset>

            <fieldset class="fgroup">
                <legend>Availability</legend>
                <label class="switch-row"><input type="checkbox" name="sale" value="1" <?= $f['sale'] === '1' ? 'checked' : '' ?>><span class="switch" aria-hidden="true"></span><span>On sale</span></label>
                <label class="switch-row"><input type="checkbox" name="instock" value="1" <?= $f['instock'] === '1' ? 'checked' : '' ?>><span class="switch" aria-hidden="true"></span><span>In stock only</span></label>
            </fieldset>

            <noscript><button class="s-btn block">Apply filters</button></noscript>
            <a class="fclear block" href="<?= e(url('/products')) ?>" data-clear>Clear all filters</a>
        </form>
    </aside>

    <section class="cat-main" aria-label="Products">
        <div class="cat-toolbar">
            <button type="button" class="s-btn ghost filters-toggle" data-filters-open><?= icon('filter') ?> Filters<?= $activeCount > 0 ? ' <span class="s-badge inline">' . $activeCount . '</span>' : '' ?></button>
            <label class="sort">
                <span>Sort by</span>
                <select name="sort" form="filter-form" aria-label="Sort products">
                    <?php foreach (Product::SORTS as $k => $label): ?><option value="<?= e($k) ?>" <?= $f['sort'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
            </label>
        </div>
        <div id="results" class="results" aria-busy="false"><?php require __DIR__ . '/_results.php'; ?></div>
    </section>
</div>
<div class="s-backdrop filters-backdrop" data-filters-backdrop></div>
