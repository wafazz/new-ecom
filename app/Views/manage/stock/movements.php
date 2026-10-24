<?php
use App\Services\StockService;
$f = $filters;
$qs = fn(array $over) => '?' . http_build_query(array_filter([...$f, ...$over], fn($v) => $v !== null && $v !== '' && $v !== 0 && $v !== 1));
$reasonOptions = ['' => 'All reasons', 'manual' => 'Manual adjustments'] + $reasons + ['order' => 'Orders placed', 'cancel' => 'Orders cancelled', 'initial' => 'Opening stock'];
$today = date('Y-m-d');
$presets = [
    ['Today', $today, $today],
    ['Last 7 days', date('Y-m-d', strtotime('-6 days')), $today],
    ['Last 30 days', date('Y-m-d', strtotime('-29 days')), $today],
    ['All time', null, null],
];
$activeFilters = $f['q'] !== '' || $f['reason'] !== '' || $f['dir'] !== '' || $f['from'] || $f['to'] || $f['actor'] !== '' || $f['product'];
?>
<div class="row-between">
    <h1>Stock Movement</h1>
    <a class="btn secondary" href="<?= e(url($base . '/stock')) ?>">← Stock Control</a>
</div>
<p class="muted">Every change to every product's stock: what moved, how much, why, and who did it. Orders and cancellations appear here automatically.</p>

<div class="tiles stock-tiles">
    <div class="tile"><span class="tile-label">Movements</span><span class="tile-value"><?= number_format($summary['count']) ?></span></div>
    <div class="tile"><span class="tile-label">Units in</span><span class="tile-value chg-up">+<?= number_format($summary['in']) ?></span></div>
    <div class="tile"><span class="tile-label">Units out</span><span class="tile-value chg-down">−<?= number_format($summary['out']) ?></span></div>
    <div class="tile"><span class="tile-label">Net change</span><span class="tile-value <?= $summary['net'] > 0 ? 'chg-up' : ($summary['net'] < 0 ? 'chg-down' : '') ?>"><?= $summary['net'] > 0 ? '+' : ($summary['net'] < 0 ? '−' : '') ?><?= number_format(abs($summary['net'])) ?></span></div>
</div>

<div class="prod-chips" role="group" aria-label="Period" style="margin-top:1rem">
    <?php foreach ($presets as [$label, $from, $to]): ?>
        <a href="<?= e(url($base . '/stock/movements' . $qs(['from' => $from, 'to' => $to, 'page' => 1]))) ?>" class="<?= $f['from'] === $from && $f['to'] === $to ? 'on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<form method="get" class="prod-filters mv-filters" id="movement-filters" action="<?= e(url($base . '/stock/movements')) ?>">
    <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search product, variation or SKU…" aria-label="Search movements" autocomplete="off">
    <select name="dir" aria-label="Direction">
        <option value="">In and out</option>
        <option value="in" <?= $f['dir'] === 'in' ? 'selected' : '' ?>>Stock in (+)</option>
        <option value="out" <?= $f['dir'] === 'out' ? 'selected' : '' ?>>Stock out (−)</option>
    </select>
    <select name="reason" aria-label="Reason">
        <?php foreach ($reasonOptions as $k => $label): ?><option value="<?= e($k) ?>" <?= $f['reason'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <select name="actor" aria-label="Changed by">
        <option value="">Anyone</option>
        <option value="system" <?= $f['actor'] === 'system' ? 'selected' : '' ?>>Customer orders / system</option>
        <?php foreach ($actors as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $f['actor'] === (string) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
    </select>
    <label class="mv-date">From <input type="date" name="from" value="<?= e((string) $f['from']) ?>" max="<?= e($today) ?>"></label>
    <label class="mv-date">To <input type="date" name="to" value="<?= e((string) $f['to']) ?>" max="<?= e($today) ?>"></label>
    <?php if ($f['product']): ?><input type="hidden" name="product" value="<?= (int) $f['product'] ?>"><?php endif; ?>
    <?php if ($f['variant']): ?><input type="hidden" name="variant" value="<?= (int) $f['variant'] ?>"><?php endif; ?>
    <noscript><button class="btn secondary">Apply</button></noscript>
    <?php if ($activeFilters): ?><a class="btn secondary sm" href="<?= e(url($base . '/stock/movements')) ?>">Clear filters</a><?php endif; ?>
</form>
<?php if ($unit): ?>
    <p>Showing <strong><?= e($unit['name']) ?></strong><?= $unit['variation'] ? ' <span class="var-chip">' . e($unit['variation']) . '</span>' : '' ?> only · now <strong><?= number_format((int) $unit['stock']) ?></strong> in stock.
        <a href="<?= e(url($base . '/stock/movements' . $qs(['product' => null, 'variant' => null, 'page' => 1]))) ?>">Show all products</a></p>
<?php elseif ($f['product']): ?>
    <p class="muted">Showing one product only. <a href="<?= e(url($base . '/stock/movements' . $qs(['product' => null, 'variant' => null, 'page' => 1]))) ?>">Show all products</a></p>
<?php endif; ?>

<?php if ($rows === []): ?>
    <p class="muted"><?= $activeFilters ? 'No stock movements match these filters.' : 'No stock movements recorded yet.' ?></p>
<?php else: ?>
<div class="table-wrap">
<table class="stock-table movement-table">
    <thead><tr><th>When</th><th>Product</th><th class="num">Change</th><th class="num">Stock</th><th>Reason</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $m): $c = (int) $m['change_qty']; ?>
        <tr>
            <td><time datetime="<?= e((string) $m['created_at']) ?>"><?= e(date('d M Y, H:i', strtotime((string) $m['created_at']))) ?></time></td>
            <td><a href="<?= e(url($base . '/stock/movements?product=' . (int) $m['product_id'] . ($m['variant_id'] ? '&variant=' . (int) $m['variant_id'] : ''))) ?>"><?= e($m['product_name'] ?? 'Deleted product') ?></a>
                <?php if ($m['label']): ?><span class="var-chip"><?= e($m['label']) ?></span><?php endif; ?>
                <br><small class="muted"><?= e($m['sku'] ?: 'no SKU') ?></small></td>
            <td class="num"><strong class="<?= $c > 0 ? 'chg-up' : 'chg-down' ?>"><?= $c > 0 ? '+' : '−' ?><?= number_format(abs($c)) ?></strong></td>
            <td class="num"><?= number_format((int) $m['qty_before']) ?> → <strong><?= number_format((int) $m['qty_after']) ?></strong></td>
            <td><?= e(StockService::reasonLabel((string) $m['reason'])) ?>
                <?php if ($m['order_no']): ?> · <?php if ($canOrders): ?><a href="<?= e(url($base . '/orders/' . (int) $m['order_id'])) ?>"><?= e($m['order_no']) ?></a><?php else: ?><?= e($m['order_no']) ?><?php endif; ?><?php endif; ?>
                <?php if ($m['note']): ?><br><small class="muted"><?= e($m['note']) ?></small><?php endif; ?></td>
            <td><?= e($m['actor_name'] ?? ($m['order_id'] ? 'Customer / system' : 'System')) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
    <p class="pager">
        <?php if ($page > 1): ?><a href="<?= e(url($base . '/stock/movements' . $qs(['page' => $page - 1]))) ?>">← Prev</a><?php endif; ?>
        Page <?= (int) $page ?> of <?= (int) $pages ?> · <?= number_format($total) ?> movements
        <?php if ($page < $pages): ?><a href="<?= e(url($base . '/stock/movements' . $qs(['page' => $page + 1]))) ?>">Next →</a><?php endif; ?>
    </p>
<?php endif; endif; ?>
<script>
(function () { // live filtering: selects and dates apply at once, search after a short pause
  var f = document.getElementById('movement-filters'); if (!f) return;
  [].forEach.call(f.querySelectorAll('select, input[type=date]'), function (el) { el.addEventListener('change', function () { f.submit(); }); });
  var q = f.elements.q, t; if (q) q.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { f.submit(); }, 500); });
  if (q && q.value) { var l = q.value.length; q.focus(); q.setSelectionRange(l, l); }
})();
</script>
