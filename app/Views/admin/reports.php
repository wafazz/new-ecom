<?php
/** Expects $w (from, to, grain), $summary, $periods, $top, $payments. Sales = order totals, cancelled orders left out. */
$money = fn(float $v): string => 'RM ' . number_format($v, 2);
$qs = fn(array $extra = []): string => '?' . http_build_query(array_merge(['from' => $w['from'], 'to' => $w['to'], 'grain' => $w['grain']], $extra));
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Sales reports</h1>
            <p class="muted">Sales are order totals, including shipping and after discounts. Cancelled orders are left out. Showing <?= e($w['from']) ?> to <?= e($w['to']) ?>.</p>
        </div>
        <a class="btn" href="<?= e(url('/admin/reports/export' . $qs())) ?>">Download CSV</a>
    </header>

    <form method="get" action="<?= e(url('/admin/reports')) ?>" class="msg-card card-form">
        <label>From <input type="date" name="from" value="<?= e($w['from']) ?>"></label>
        <label>To <input type="date" name="to" value="<?= e($w['to']) ?>"></label>
        <label>Group by
            <select name="grain"><option value="day" <?= $w['grain'] === 'day' ? 'selected' : '' ?>>Day</option><option value="month" <?= $w['grain'] === 'month' ? 'selected' : '' ?>>Month</option></select></label>
        <div class="msg-row"><span></span><button class="btn">Show</button></div>
    </form>

    <section class="msg-card">
        <h2><?= e($money($summary['sales'])) ?></h2>
        <p class="muted"><?= (int) $summary['orders'] ?> orders · average <?= e($money($summary['average'])) ?></p>
    </section>

    <section class="msg-card">
        <h2>By <?= $w['grain'] === 'month' ? 'month' : 'day' ?></h2>
        <?php if ($periods === []): ?>
            <p class="muted">No sales in this period.</p>
        <?php else: ?>
            <div class="msg-table-wrap">
                <table class="msg-table">
                    <thead><tr><th><?= $w['grain'] === 'month' ? 'Month' : 'Day' ?></th><th>Orders</th><th>Sales</th></tr></thead>
                    <tbody>
                        <?php foreach ($periods as $p): ?>
                            <tr><td><?= e($p['period']) ?></td><td><?= (int) $p['orders'] ?></td><td><?= e($money($p['sales'])) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="msg-card">
        <h2>Top products</h2>
        <?php if ($top === []): ?>
            <p class="muted">No products sold in this period.</p>
        <?php else: ?>
            <div class="msg-table-wrap">
                <table class="msg-table">
                    <thead><tr><th>Product</th><th>Units</th><th>Sales</th></tr></thead>
                    <tbody>
                        <?php foreach ($top as $t): ?>
                            <tr><td><?= e($t['name']) ?></td><td><?= (int) $t['units'] ?></td><td><?= e($money($t['sales'])) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="msg-card">
        <h2>Payment methods</h2>
        <?php if ($payments === []): ?>
            <p class="muted">No payments in this period.</p>
        <?php else: ?>
            <div class="msg-table-wrap">
                <table class="msg-table">
                    <thead><tr><th>Method</th><th>Orders</th><th>Sales</th></tr></thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                            <tr><td><?= e($p['method']) ?></td><td><?= (int) $p['orders'] ?></td><td><?= e($money($p['sales'])) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
