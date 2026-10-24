<?php
use App\Models\Product;
$f = $filters;
$qs = fn(array $over) => '?' . http_build_query(array_filter([...$f, ...$over], fn($v) => $v !== null && $v !== '' && $v !== 0 && $v !== 'name' && $v !== 1));
$chips = [
    ['All items', $summary['items'], ''],
    ['In stock', $summary['ok'], 'ok'],
    ['Low stock', $summary['low'], 'low'],
    ['Out of stock', $summary['out'], 'out'],
];
$level = fn(int $s) => $s <= 0 ? 'out' : ($s <= Product::LOW_STOCK ? 'low' : 'ok');
$focus = $f['product'] && $groups !== [] ? $groups[0]['name'] : null;
// A product with variations starts collapsed, unless you came for it (Manage link, search, level filter): then its variations are open.
$autoOpen = $f['product'] || $f['q'] !== '' || $f['level'] !== '';
$levelBadge = function (int $out, int $low, int $n, int $stock): string {
    if ($n > 0 && $out === $n) { return '<span class="badge st-cancelled">All out of stock</span>'; }
    if ($out > 0 || $low > 0) {
        $bits = array_filter([$out > 0 ? $out . ' out' : '', $low > 0 ? $low . ' low' : '']);
        return '<span class="badge st-pending">' . e(implode(' · ', $bits)) . '</span>';
    }
    return '<span class="badge active">In stock</span>';
};
?>
<div class="msg-page">
<header class="msg-head">
    <div>
        <h1>Stock control</h1>
        <p class="muted">Stock is changed only on this page. Orders take stock off automatically and cancelled orders put it back; every change is written to the history.</p>
    </div>
    <a class="btn secondary" href="<?= e(url($base . '/stock/movements')) ?>">Stock movement</a>
</header>

<div class="tiles stock-tiles">
    <div class="tile"><span class="tile-label">Sellable items</span><span class="tile-value"><?= number_format($summary['items']) ?></span></div>
    <div class="tile"><span class="tile-label">Units on hand</span><span class="tile-value"><?= number_format($summary['units']) ?></span></div>
    <div class="tile"><span class="tile-label">Low (<?= (int) Product::LOW_STOCK ?> or fewer)</span><span class="tile-value" style="<?= $summary['low'] ? 'color:var(--warn-fg)' : '' ?>"><?= number_format($summary['low']) ?></span></div>
    <div class="tile"><span class="tile-label">Out of stock</span><span class="tile-value" style="<?= $summary['out'] ? 'color:var(--bad-fg)' : '' ?>"><?= number_format($summary['out']) ?></span></div>
</div>

<div class="prod-chips" role="group" aria-label="Stock level" style="margin-top:1rem">
    <?php foreach ($chips as [$label, $n, $lv]): ?>
        <a href="<?= e(url($base . '/stock' . $qs(['level' => $lv, 'page' => 1]))) ?>" class="<?= $f['level'] === $lv ? 'on' : '' ?><?= $lv === 'out' && $n > 0 ? ' warn' : '' ?>"><?= e($label) ?> <span><?= (int) $n ?></span></a>
    <?php endforeach; ?>
</div>

<form method="get" class="prod-filters" id="stock-filters" action="<?= e(url($base . '/stock')) ?>">
    <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search product, variation or SKU…" aria-label="Search stock" autocomplete="off">
    <select name="category" aria-label="Category">
        <option value="">All categories</option>
        <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= (int) $f['category'] === (int) $c['id'] ? 'selected' : '' ?>><?= $c['depth'] ? '— ' : '' ?><?= e($c['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="brand" aria-label="Brand">
        <option value="">All brands</option>
        <?php foreach ($brands as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= (int) $f['brand'] === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="sort" aria-label="Sort">
        <?php foreach ($sorts as $k => $label): ?><option value="<?= e($k) ?>" <?= $f['sort'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <input type="hidden" name="level" value="<?= e($f['level']) ?>">
    <?php if ($f['product']): ?><input type="hidden" name="product" value="<?= (int) $f['product'] ?>"><?php endif; ?>
    <noscript><button class="btn secondary">Apply</button></noscript>
</form>
<?php if ($f['product']): ?>
    <p class="muted">Showing only <strong><?= e($focus ?? 'one product') ?></strong>. <a href="<?= e(url($base . '/stock' . $qs(['product' => null, 'page' => 1]))) ?>">Show all items</a></p>
<?php endif; ?>

<div id="stock-root" data-endpoint="<?= e(url($base . '/stock/adjust')) ?>" data-token="<?= e(\App\Core\Csrf::token()) ?>" data-low="<?= (int) Product::LOW_STOCK ?>" data-reasons="<?= e(json_encode($reasons, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
<?php if ($groups === []): ?>
    <p class="muted">No items match<?= $f['q'] !== '' || $f['level'] !== '' || $f['category'] || $f['brand'] ? ' these filters' : ' yet: add a product first' ?>.</p>
<?php else: ?>
<div class="stk-tools" data-stk-tools hidden>
    <button type="button" class="btn secondary sm" data-expand-all>Expand all variations</button>
    <button type="button" class="btn secondary sm" data-collapse-all>Collapse all</button>
</div>
<div class="table-wrap">
<table class="prod-table stock-table">
    <thead><tr><th>Item</th><th>Category</th><th>Brand</th><th class="num">In stock</th><th>Level</th><th></th></tr></thead>
    <?php foreach ($groups as $g):
        $pid = (int) $g['product_id'];
        if ($g['single']):
            $it = $g['units'][0]; $s = (int) $it['stock']; $lv = $level($s);
            $title = $it['name'];
    ?>
    <tbody class="stk-group single">
        <tr data-product="<?= $pid ?>" data-variant="0" data-name="<?= e($it['name']) ?>" data-variation="" data-sku="<?= e((string) $it['sku']) ?>" data-stock="<?= $s ?>" class="<?= $it['status'] === 'draft' ? 'is-draft' : '' ?>">
            <td class="pname"><strong><?= e($it['name']) ?></strong>
                <?php if ($it['status'] === 'draft'): ?><span class="badge draft">draft</span><?php endif; ?>
                <br><small class="muted"><?= e($it['sku'] ?: 'no SKU') ?></small></td>
            <td><?= e($it['category_name'] ?? '—') ?></td>
            <td><?= e($it['brand_name'] ?? '—') ?></td>
            <td class="num"><span class="stock-fixed stock-now" data-level="<?= $lv ?>"><?= number_format($s) ?></span></td>
            <td><?php if ($lv === 'out'): ?><span class="badge st-cancelled">Out of stock</span><?php elseif ($lv === 'low'): ?><span class="badge st-pending">Low</span><?php else: ?><span class="badge active">In stock</span><?php endif; ?></td>
            <td class="act">
                <button type="button" class="btn sm" data-adjust aria-label="Adjust stock of <?= e($title) ?>" hidden>Adjust</button>
                <a class="btn secondary sm" href="<?= e(url($base . '/stock/movements?product=' . $pid)) ?>">Movement</a>
            </td>
        </tr>
    </tbody>
    <?php else:
        $open = $autoOpen;
        $childIds = implode(' ', array_map(fn($u) => 'stk-c-' . (int) $u['variant_id'], $g['units']));
        $tl = $g['stock'] <= 0 ? 'out' : ($g['stock'] <= Product::LOW_STOCK ? 'low' : 'ok');
    ?>
    <tbody class="stk-group has-variations<?= $open ? ' open' : '' ?>" data-group="<?= $pid ?>">
        <tr class="stk-main<?= $g['status'] === 'draft' ? ' is-draft' : '' ?>" data-toggle-group>
            <td class="pname">
                <button type="button" class="stk-chev" aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="<?= e($childIds) ?>" aria-label="Show or hide the <?= (int) $g['variations'] ?> variations of <?= e($g['name']) ?>"><svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></button>
                <strong><?= e($g['name']) ?></strong>
                <span class="var-count"><?= (int) $g['variations'] ?> variations</span>
                <?php if ($g['status'] === 'draft'): ?><span class="badge draft">draft</span><?php endif; ?>
            </td>
            <td><?= e($g['category_name'] ?? '—') ?></td>
            <td><?= e($g['brand_name'] ?? '—') ?></td>
            <td class="num"><span class="stock-fixed" data-level="<?= $tl ?>" title="Total of all variations"><?= number_format($g['stock']) ?></span><br><small class="muted">total</small></td>
            <td><?= $levelBadge((int) $g['out'], (int) $g['low'], (int) $g['variations'], (int) $g['stock']) ?></td>
            <td class="act"><a class="btn secondary sm" href="<?= e(url($base . '/stock/movements?product=' . $pid)) ?>">Movement</a></td>
        </tr>
        <?php foreach ($g['units'] as $it):
            $s = (int) $it['stock']; $lv = $level($s); $vid = (int) $it['variant_id'];
            $title = $it['name'] . ' — ' . $it['variation'];
        ?>
        <tr class="stk-child<?= $autoOpen && !$it['match'] ? ' dim' : '' ?>" id="stk-c-<?= $vid ?>"<?= $open ? '' : ' hidden' ?> data-product="<?= $pid ?>" data-variant="<?= $vid ?>" data-name="<?= e($it['name']) ?>" data-variation="<?= e((string) $it['variation']) ?>" data-sku="<?= e((string) $it['sku']) ?>" data-stock="<?= $s ?>">
            <td class="pname"><span class="var-chip"><?= e($it['variation'] ?: 'Variation') ?></span> <small class="muted"><?= e($it['sku'] ?: 'no SKU') ?></small></td>
            <td></td><td></td>
            <td class="num"><span class="stock-fixed stock-now" data-level="<?= $lv ?>"><?= number_format($s) ?></span></td>
            <td><?php if ($lv === 'out'): ?><span class="badge st-cancelled">Out of stock</span><?php elseif ($lv === 'low'): ?><span class="badge st-pending">Low</span><?php else: ?><span class="badge active">In stock</span><?php endif; ?></td>
            <td class="act">
                <button type="button" class="btn sm" data-adjust aria-label="Adjust stock of <?= e($title) ?>" hidden>Adjust</button>
                <a class="btn secondary sm" href="<?= e(url($base . '/stock/movements?product=' . $pid . '&variant=' . $vid)) ?>">Movement</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <?php endif; endforeach; ?>
</table>
</div>
<noscript><style>.stk-group .stk-child[hidden]{display:table-row}</style></noscript>
<?php if ($pages > 1): ?>
    <p class="pager">
        <?php if ($page > 1): ?><a href="<?= e(url($base . '/stock' . $qs(['page' => $page - 1]))) ?>">← Prev</a><?php endif; ?>
        Page <?= (int) $page ?> of <?= (int) $pages ?> · <?= (int) $total ?> products
        <?php if ($page < $pages): ?><a href="<?= e(url($base . '/stock' . $qs(['page' => $page + 1]))) ?>">Next →</a><?php endif; ?>
    </p>
<?php endif; endif; ?>
</div>
<noscript><p class="muted">Turn on JavaScript to adjust stock from this page.</p></noscript>
</div>
