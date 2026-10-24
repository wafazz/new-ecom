<?php
/** The results block of the catalogue: count, active filter chips, grid and "load more". Rendered inline and (for AJAX) on its own. */
$f = $filters;
$qs = function (array $over = []) use ($f): string {
    $all = array_filter([...$f, ...$over], fn($v) => $v !== null && $v !== '' && $v !== 'newest' && $v !== 1);
    return url('/products') . ($all ? '?' . http_build_query($all) : '');
};
$chips = [];
if ($f['q'] !== '') { $chips[] = ['“' . $f['q'] . '”', $qs(['q' => null, 'page' => 1])]; }
if ($f['category'] !== '') { $chips[] = [$catName ?? $f['category'], $qs(['category' => null, 'page' => 1])]; }
if ($f['brand'] !== '') { $chips[] = [$brandName ?? $f['brand'], $qs(['brand' => null, 'page' => 1])]; }
if ($f['min'] !== null || $f['max'] !== null) {
    $chips[] = [($f['min'] !== null && $f['max'] !== null ? money($f['min']) . ' – ' . money($f['max']) : ($f['min'] !== null ? 'From ' . money($f['min']) : 'Up to ' . money($f['max']))), $qs(['min' => null, 'max' => null, 'page' => 1])];
}
if ($f['sale'] === '1') { $chips[] = ['On sale', $qs(['sale' => null, 'page' => 1])]; }
if ($f['instock'] === '1') { $chips[] = ['In stock', $qs(['instock' => null, 'page' => 1])]; }
?>
<div class="res-head">
    <p class="res-count" aria-live="polite"><strong><?= number_format($total) ?></strong> <?= $total === 1 ? 'product' : 'products' ?></p>
    <?php if ($chips !== []): ?>
        <div class="res-chips">
            <?php foreach ($chips as [$label, $href]): ?><a class="fchip" href="<?= e($href) ?>" data-chip aria-label="Remove filter: <?= e($label) ?>"><?= e($label) ?> <?= icon('x', 'i i-sm') ?></a><?php endforeach; ?>
            <a class="fclear" href="<?= e(url('/products')) ?>" data-clear>Clear all</a>
        </div>
    <?php endif; ?>
</div>
<?php if ($products === []): ?>
    <div class="empty-state">
        <?= icon('search', 'i i-xl') ?>
        <h2>Nothing matches</h2>
        <p class="muted">Try a different search, or remove a filter.</p>
        <p><a class="s-btn" href="<?= e(url('/products')) ?>" data-clear>See all products</a></p>
    </div>
<?php else: ?>
    <div class="pgrid" id="pgrid"><?php require __DIR__ . '/_cards.php'; ?></div>
    <?php if ($pages > 1): ?>
        <div class="load-more">
            <?php if ($page < $pages): ?><button type="button" class="s-btn ghost" data-load-more data-next="<?= $page + 1 ?>" hidden>Show more products</button><?php endif; ?>
            <p class="pager s-pager">
                <?php if ($page > 1): ?><a href="<?= e($qs(['page' => $page - 1])) ?>">← Prev</a><?php endif; ?>
                Page <?= (int) $page ?> of <?= (int) $pages ?>
                <?php if ($page < $pages): ?><a href="<?= e($qs(['page' => $page + 1])) ?>">Next →</a><?php endif; ?>
            </p>
        </div>
    <?php endif; ?>
<?php endif; ?>
