<?php
/** Expects $count and $lines. Blind count: no system quantities are shown. */
?>
<div class="msg-page stock-sheet">
    <header class="msg-head">
        <div>
            <h1><?= e($count['title']) ?></h1>
            <p class="muted"><?= e($count['scope_label']) ?> · write what you count in each box, then save.</p>
        </div>
        <button type="button" class="btn secondary no-print" data-print>Print sheet</button>
    </header>
    <?php if ($count['status'] === 'applied'): ?>
        <p class="alert">This count is closed.</p>
    <?php else: ?>
    <form method="post" action="<?= e(url('/admin/stock-counts/' . (int) $count['id'] . '/counts')) ?>" class="msg-card">
        <?= csrf_field() ?>
        <div class="msg-table-wrap">
            <table class="msg-table count-table">
                <thead><tr><th>Item</th><th>SKU</th><th>Counted</th></tr></thead>
                <tbody>
                    <?php foreach ($lines as $l): ?>
                        <tr>
                            <td><?= e($l['label']) ?></td>
                            <td><?= e((string) $l['sku']) ?></td>
                            <td><input name="counted[<?= (int) $l['id'] ?>]" inputmode="numeric" value="<?= $l['counted_qty'] !== null ? (int) $l['counted_qty'] : '' ?>" aria-label="Counted quantity for <?= e($l['label']) ?>" class="count-input"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="msg-row no-print"><span></span><button class="btn">Save counts</button></div>
    </form>
    <?php endif; ?>
</div>
