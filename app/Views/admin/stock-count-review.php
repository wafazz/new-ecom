<?php
/** Expects $count, $diffs (counted and different from the system), $uncounted. */
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Review: <?= e($count['title']) ?></h1>
            <p class="muted">Only lines that were counted and differ from the system are listed. Applying writes each as a stock correction, once.</p>
        </div>
    </header>
    <?php if ($uncounted > 0): ?><p class="alert">Still to count: <?= (int) $uncounted ?> line(s). They are left as they are when you apply.</p><?php endif; ?>
    <section class="msg-card">
        <?php if ($diffs === []): ?>
            <p class="muted">No differences to apply.</p>
        <?php else: ?>
            <div class="msg-table-wrap">
                <table class="msg-table">
                    <thead><tr><th>Item</th><th>SKU</th><th>System</th><th>Counted</th><th>Change</th></tr></thead>
                    <tbody>
                        <?php foreach ($diffs as $d): $delta = (int) $d['counted_qty'] - (int) $d['system_qty']; ?>
                            <tr><td><?= e($d['label']) ?></td><td><?= e((string) $d['sku']) ?></td><td><?= (int) $d['system_qty'] ?></td><td><?= (int) $d['counted_qty'] ?></td><td><?= $delta > 0 ? '+' : '' ?><?= $delta ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($count['status'] === 'open'): ?>
                <form method="post" action="<?= e(url('/admin/stock-counts/' . (int) $count['id'] . '/apply')) ?>" class="msg-row" data-swal-confirm="Apply <?= count($diffs) ?> correction(s)?" data-swal-text="These change stock now. Each change is recorded in the stock movement log." data-swal-button="Apply">
                    <?= csrf_field() ?><span class="muted">Applies <?= count($diffs) ?> correction(s).</span><button class="btn">Apply corrections</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <p class="muted"><a href="<?= e(url('/admin/stock-counts/' . (int) $count['id'])) ?>">Back to the counting sheet</a></p>
    </section>
</div>
