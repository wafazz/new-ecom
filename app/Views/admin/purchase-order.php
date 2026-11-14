<?php
/** Expects $po (with supplier) and $items (with product name, ordered, received). */
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Purchase order #<?= (int) $po['id'] ?></h1>
            <p class="muted"><?= e($po['supplier']) ?> · status: <?= e($po['status']) ?></p>
        </div>
        <?php if ($po['status'] === 'draft'): ?>
            <form method="post" action="<?= e(url('/admin/purchase-orders/' . (int) $po['id'] . '/send')) ?>"><?= csrf_field() ?><button class="btn">Mark as sent to supplier</button></form>
        <?php endif; ?>
    </header>
    <section class="msg-card">
        <form method="post" action="<?= e(url('/admin/purchase-orders/' . (int) $po['id'] . '/receive')) ?>" data-swal-confirm="Record this delivery?" data-swal-text="Stock goes up by the quantities entered." data-swal-button="Record delivery">
            <?= csrf_field() ?>
            <div class="msg-table-wrap">
                <table class="msg-table">
                    <thead><tr><th>Product</th><th>SKU</th><th>Ordered</th><th>Received so far</th><th>Arrived now</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $it): $left = (int) $it['qty_ordered'] - (int) $it['qty_received']; ?>
                            <tr>
                                <td><?= e($it['name']) ?></td>
                                <td><?= e((string) $it['sku']) ?></td>
                                <td><?= (int) $it['qty_ordered'] ?></td>
                                <td><?= (int) $it['qty_received'] ?></td>
                                <td><?php if ($left > 0 && in_array($po['status'], ['sent', 'part_received'], true)): ?>
                                    <input name="received[<?= (int) $it['id'] ?>]" inputmode="numeric" placeholder="0" aria-label="Arrived now for <?= e($it['name']) ?>" style="width:6rem"> <small class="muted">max <?= $left ?></small>
                                <?php else: ?><span class="muted">—</span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (in_array($po['status'], ['sent', 'part_received'], true)): ?>
                <div class="msg-row"><span></span><button class="btn">Record delivery</button></div>
            <?php endif; ?>
        </form>
    </section>
</div>
