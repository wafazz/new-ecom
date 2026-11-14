<?php
/** Expects $products, $pid (chosen product or 0), $breaks (existing rows). */
$rows = $breaks;
while (count($rows) < 5) { $rows[] = ['min_qty' => '', 'unit_price' => '']; }
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Quantity prices</h1>
            <p class="muted">A lower unit price once a shopper buys enough of one product. The break must be cheaper than the current price. Leave a row blank to remove it.</p>
        </div>
    </header>
    <form method="get" action="<?= e(url('/admin/price-breaks')) ?>" class="msg-card card-form">
        <label>Product
            <select name="product" onchange="this.form.submit()">
                <option value="0">Choose…</option>
                <?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === $pid ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
            </select></label>
        <noscript><button class="btn secondary">Show</button></noscript>
    </form>
    <?php if ($pid > 0): ?>
    <form method="post" action="<?= e(url('/admin/price-breaks')) ?>" class="msg-card card-form">
        <?= csrf_field() ?>
        <input type="hidden" name="product_id" value="<?= (int) $pid ?>">
        <?php foreach ($rows as $r): ?>
            <div class="bill-row">
                <label>Buy at least <input name="min_qty[]" inputmode="numeric" value="<?= e((string) $r['min_qty']) ?>" placeholder="10"></label>
                <label>Price each (RM) <input name="unit_price[]" inputmode="decimal" value="<?= e((string) $r['unit_price']) ?>" placeholder="8.00"></label>
            </div>
        <?php endforeach; ?>
        <div class="msg-row"><span></span><button class="btn">Save quantity prices</button></div>
    </form>
    <?php endif; ?>
</div>
