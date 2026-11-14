<?php
/** Expects $suppliers, $products, $suggestions (low stock), $orders. */
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Purchase orders</h1>
            <p class="muted">Order stock from suppliers. Stock goes up only when you record what arrived, and never past what was ordered.</p>
        </div>
    </header>

    <section class="msg-card">
        <h2>Low stock</h2>
        <?php if ($suggestions === []): ?>
            <p class="muted">Nothing is low on stock.</p>
        <?php else: ?>
            <ul class="perm-chips"><?php foreach ($suggestions as $s): ?><li><?= e($s['name']) ?> · <?= (int) $s['stock'] ?> left</li><?php endforeach; ?></ul>
        <?php endif; ?>
    </section>

    <form method="post" action="<?= e(url('/admin/purchase-orders/supplier')) ?>" class="msg-card card-form">
        <?= csrf_field() ?>
        <h2>Add a supplier</h2>
        <label>Name <input name="name" maxlength="120" required></label>
        <label>Contact (optional) <input name="contact" maxlength="190"></label>
        <label>Lead time in days (optional) <input name="lead_days" inputmode="numeric"></label>
        <div class="msg-row"><span></span><button class="btn secondary">Save supplier</button></div>
    </form>

    <form method="post" action="<?= e(url('/admin/purchase-orders')) ?>" class="msg-card card-form">
        <?= csrf_field() ?>
        <h2>New purchase order</h2>
        <label>Supplier
            <select name="supplier_id" required><option value="">Choose…</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
        <?php for ($i = 0; $i < 5; $i++): ?>
            <div class="bill-row">
                <label>Product <select name="product[]"><option value="">—</option><?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> (<?= (int) $p['stock'] ?> in stock)</option><?php endforeach; ?></select></label>
                <label>Qty <input name="qty[]" inputmode="numeric" style="width:6rem"></label>
                <label>Cost each (RM) <input name="cost[]" inputmode="decimal" style="width:7rem"></label>
            </div>
        <?php endfor; ?>
        <div class="msg-row"><span></span><button class="btn">Make draft</button></div>
    </form>

    <section class="msg-card">
        <h2>Orders</h2>
        <?php if ($orders === []): ?>
            <p class="muted">No purchase orders yet.</p>
        <?php else: ?>
            <div class="msg-table-wrap"><table class="msg-table"><thead><tr><th>#</th><th>Supplier</th><th>Status</th><th>Ordered</th><th>Received</th><th></th></tr></thead><tbody>
                <?php foreach ($orders as $o): ?>
                    <tr><td>#<?= (int) $o['id'] ?></td><td><?= e($o['supplier']) ?></td><td><?= e($o['status']) ?></td><td><?= (int) $o['ordered'] ?></td><td><?= (int) $o['received'] ?></td>
                        <td><a class="btn secondary sm" href="<?= e(url('/admin/purchase-orders/' . (int) $o['id'])) ?>">Open</a></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
</div>
