<?php
/** Expects $w (from, to), $categories, $products, $report (monthly). Prices include SST, so SST is part of the amount paid. */
$money = fn(float $v): string => 'RM ' . number_format($v, 2);
$pct = fn($v): string => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>SST</h1>
            <p class="muted">Prices already include SST. The SST shown is part of the amount paid. A product uses its own rate if it has one, otherwise its category's. Blank means no SST. Check the rates with your accountant before you rely on them.</p>
        </div>
        <a class="btn secondary" href="<?= e(url('/admin/tax/export?from=' . $w['from'] . '&to=' . $w['to'])) ?>">Download CSV</a>
    </header>

    <section class="msg-card">
        <h2>Rates by category</h2>
        <div class="msg-table-wrap">
            <table class="msg-table">
                <thead><tr><th>Category</th><th>Rate (%)</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($categories as $c): ?>
                        <tr>
                            <td><?= e($c['name']) ?></td>
                            <td colspan="2">
                                <form method="post" action="<?= e(url('/admin/tax/category/' . (int) $c['id'])) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input name="rate" inputmode="decimal" value="<?= e($pct($c['tax_rate'])) ?>" placeholder="none" aria-label="Rate for <?= e($c['name']) ?>" style="width:6rem">
                                    <button class="btn secondary sm">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="msg-card">
        <h2>Rate for one product</h2>
        <p class="muted">Overrides the category. Enter 0 to make a product zero-rated. Leave blank to use its category.</p>
        <form method="post" action="<?= e(url('/admin/tax/product')) ?>" class="card-form">
            <?= csrf_field() ?>
            <label>Product
                <select name="product">
                    <?php foreach ($products as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" data-rate="<?= e($pct($p['tax_rate'])) ?>"><?= e($p['name']) ?><?= $p['tax_rate'] !== null ? ' (' . e($pct($p['tax_rate'])) . '%)' : '' ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label>Rate (%) <input name="rate" inputmode="decimal" placeholder="blank = category"></label>
            <div class="msg-row"><span></span><button class="btn">Save product rate</button></div>
        </form>
    </section>

    <form method="get" action="<?= e(url('/admin/tax')) ?>" class="msg-card card-form">
        <label>From <input type="date" name="from" value="<?= e($w['from']) ?>"></label>
        <label>To <input type="date" name="to" value="<?= e($w['to']) ?>"></label>
        <div class="msg-row"><span></span><button class="btn">Show</button></div>
    </form>

    <section class="msg-card">
        <h2>SST by month</h2>
        <?php if ($report === []): ?>
            <p class="muted">No orders in this period.</p>
        <?php else: ?>
            <div class="msg-table-wrap">
                <table class="msg-table">
                    <thead><tr><th>Month</th><th>Orders</th><th>Sales (incl. SST)</th><th>Included SST</th></tr></thead>
                    <tbody>
                        <?php foreach ($report as $r): ?>
                            <tr><td><?= e($r['period']) ?></td><td><?= (int) $r['orders'] ?></td><td><?= e($money($r['sales'])) ?></td><td><?= e($money($r['sst'])) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
