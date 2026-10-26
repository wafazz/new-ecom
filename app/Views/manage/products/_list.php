<?php
/** Fragment: chips + table + pager. Rendered inline on the page and on its own for live updates. */
use App\Services\PricingService as P;
$f = $filters;
$qs = fn(array $over) => '?' . http_build_query(array_filter([...$f, ...$over], fn($v) => $v !== null && $v !== '' && $v !== 0 && $v !== 'newest'));
$chips = [
    ['All', $counts['total'], ['status' => '', 'stock' => ''], $f['status'] === '' && $f['stock'] === ''],
    ['Active', $counts['active'], ['status' => 'active', 'stock' => ''], $f['status'] === 'active' && $f['stock'] === ''],
    ['Draft', $counts['draft'], ['status' => 'draft', 'stock' => ''], $f['status'] === 'draft' && $f['stock'] === ''],
    ['Low stock', $counts['low'], ['status' => '', 'stock' => 'low'], $f['stock'] === 'low'],
    ['Out of stock', $counts['out_of_stock'], ['status' => '', 'stock' => 'out'], $f['stock'] === 'out'],
];
$filtered = $f['q'] !== '' || $f['category'] || $f['brand'] || $f['status'] !== '' || $f['stock'] !== '';
?>
<div class="prod-chips" role="group" aria-label="Quick filters" data-total="<?= (int) $total ?>">
    <?php foreach ($chips as [$label, $n, $set, $on]): ?>
        <a href="<?= e(url($base . '/products' . $qs($set + ['page' => 1]))) ?>" data-set="<?= e(json_encode($set)) ?>" class="<?= $on ? 'on' : '' ?><?= $label === 'Out of stock' && $n > 0 ? ' warn' : '' ?>"><?= e($label) ?> <span><?= (int) $n ?></span></a>
    <?php endforeach; ?>
</div>

<div class="bulk-bar" id="bulk-bar" hidden>
    <strong><span id="bulk-count">0</span> selected</strong>
    <button type="button" class="btn secondary sm" data-bulk="activate">Activate</button>
    <button type="button" class="btn secondary sm" data-bulk="draft">Move to draft</button>
    <button type="button" class="btn secondary sm danger-btn" data-bulk="delete">Delete</button>
    <button type="button" class="link" data-bulk-clear>Clear</button>
</div>

<?php if ($products === []): ?>
    <div class="prod-empty">
        <?php if ($filtered): ?>
            <p><strong>No products match.</strong></p><p class="muted">Try a different search, or <a href="<?= e(url($base . '/products')) ?>" data-reset>clear all filters</a>.</p>
        <?php else: ?>
            <p><strong>No products yet.</strong></p><p class="muted">Add your first product to start selling.</p>
            <p><a class="btn" href="<?= e(url($base . '/products/create')) ?>" data-open-product="new">Add product</a></p>
        <?php endif; ?>
    </div>
<?php else: ?>
<div class="table-wrap">
<table class="prod-table">
    <thead><tr>
        <th class="chk"><input type="checkbox" id="select-all" aria-label="Select all products on this page"></th>
        <th></th><th>Product</th><th>Category</th><th>Brand</th>
        <th class="num">Price</th>
        <?php if ($isAdmin): ?><th class="num">Margin</th><?php endif; ?>
        <th>Stock</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($products as $p):
        $eff = P::effective($p); $id = (int) $p['id'];
        $hasVar = (int) $p['variant_count'] > 0;
        $digital = \App\Services\DigitalService::isDigital($p);
        $stock = $digital ? \App\Services\DigitalService::stock($p) : (int) ($hasVar ? $p['variant_stock'] : $p['stock']);
        $level = $stock <= 0 ? 'out' : ($stock <= \App\Models\Product::LOW_STOCK ? 'low' : 'ok');
        $cost = (float) $p['base_price'];
        $margin = (float) $eff['price'] - $cost;
    ?>
        <tr data-id="<?= $id ?>" data-name="<?= e($p['name']) ?>" class="<?= $p['status'] === 'draft' ? 'is-draft' : '' ?>">
            <td class="chk"><input type="checkbox" class="row-check" value="<?= $id ?>" aria-label="Select <?= e($p['name']) ?>"></td>
            <td><?php if ($p['image']): ?><img class="thumb" src="<?= e(url($p['image'])) ?>" alt="" loading="lazy"><?php else: ?><span class="thumb ph" aria-hidden="true"></span><?php endif; ?></td>
            <td class="pname"><a href="<?= e(url($base . '/products/' . $id . '/edit')) ?>" data-open-product="<?= $id ?>"><?= e($p['name']) ?></a>
                <small class="muted"><?= e($p['sku'] ?: 'no SKU') ?><?= $hasVar ? ' · ' . (int) $p['variant_count'] . ' variants' : '' ?></small></td>
            <td><?= e($p['category_name'] ?? '—') ?></td>
            <td><?= e($p['brand_name'] ?? '—') ?></td>
            <td class="num"><?= e(money($eff['price'])) ?><?= $eff['on_sale'] ? ' <span class="badge sale">sale</span><br><small class="muted"><s>' . e(money($eff['retail'])) . '</s></small>' : '' ?></td>
            <?php if ($isAdmin): ?><td class="num <?= $margin < 0 ? 'neg' : '' ?>"><?= e(money($margin)) ?><br><small class="muted"><?= (float) $eff['price'] > 0 ? e(number_format($margin / (float) $eff['price'] * 100, 0)) . '%' : '' ?></small></td><?php endif; ?>
            <td>
                <span class="stock-fixed" data-level="<?= $level ?>" title="<?= $digital ? 'Digital product' : ($hasVar ? 'Total of the variations' : 'In stock') ?>"><?= $digital && $stock >= \App\Services\DigitalService::UNLIMITED ? 'Digital' : number_format($stock) ?></span>
                <?php if (\App\Core\Auth::can('stock.manage')): ?><a class="stock-link" href="<?= e(url($base . '/stock?product=' . $id)) ?>">Manage</a><?php endif; ?>
            </td>
            <td><button type="button" class="sw" role="switch" aria-checked="<?= $p['status'] === 'active' ? 'true' : 'false' ?>" aria-label="Visible in shop: <?= e($p['name']) ?>"><i></i></button>
                <span class="sw-label muted"><?= e($p['status']) ?></span></td>
            <td class="act"><a href="<?= e(url($base . '/products/' . $id . '/edit')) ?>" data-open-product="<?= $id ?>" class="btn secondary sm">Edit</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
    <p class="pager">
        <?php if ($page > 1): ?><a href="<?= e(url($base . '/products' . $qs(['page' => $page - 1]))) ?>" data-page="<?= $page - 1 ?>">← Prev</a><?php endif; ?>
        Page <?= (int) $page ?> of <?= (int) $pages ?> · <?= (int) $total ?> products
        <?php if ($page < $pages): ?><a href="<?= e(url($base . '/products' . $qs(['page' => $page + 1]))) ?>" data-page="<?= $page + 1 ?>">Next →</a><?php endif; ?>
    </p>
<?php endif; endif; ?>
