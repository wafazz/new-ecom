<?php
/** Expects $w, $lines (first 200), $lineCount, $totals (debit, credit, balanced), $accounts. */
$money = fn(float $v): string => number_format($v, 2);
$qs = 'from=' . urlencode($w['from']) . '&to=' . urlencode($w['to']);
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Accounting export</h1>
            <p class="muted">A journal of sales, postage, SST, payments and refunds for <?= e($w['from']) ?> to <?= e($w['to']) ?>. Cancelled orders are left out. The file is only made when debits equal credits.</p>
        </div>
        <a class="btn" href="<?= e(url('/admin/accounting/export?' . $qs)) ?>">Download journal (CSV)</a>
    </header>

    <section class="msg-card">
        <form method="get" action="<?= e(url('/admin/accounting')) ?>" class="card-form">
            <label>From <input type="date" name="from" value="<?= e($w['from']) ?>"></label>
            <label>To <input type="date" name="to" value="<?= e($w['to']) ?>"></label>
            <div class="msg-row"><span></span><button class="btn secondary">Show</button></div>
        </form>
        <p class="<?= $totals['balanced'] ? 'alert ok' : 'alert bad' ?>">
            Debits RM<?= e($money($totals['debit'])) ?> · Credits RM<?= e($money($totals['credit'])) ?> ·
            <?= $totals['balanced'] ? 'balanced' : 'NOT balanced: the file will not be made' ?> · <?= (int) $lineCount ?> lines
        </p>
    </section>

    <section class="msg-card">
        <h2>Journal lines</h2>
        <?php if ($lines === []): ?>
            <p class="muted">No sales or refunds in this period.</p>
        <?php else: ?>
            <div class="msg-table-wrap">
                <table class="msg-table">
                    <thead><tr><th>Date</th><th>Ref</th><th>Account</th><th>Debit</th><th>Credit</th></tr></thead>
                    <tbody>
                        <?php foreach ($lines as $l): ?>
                            <tr><td><?= e($l['date']) ?></td><td><?= e($l['ref']) ?></td><td><?= e($l['account']) ?> <?= e($l['name']) ?></td><td><?= e($money($l['debit'])) ?></td><td><?= e($money($l['credit'])) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($lineCount > 200): ?><p class="muted">Showing the first 200 lines. The download has all of them.</p><?php endif; ?>
        <?php endif; ?>
    </section>
</div>
