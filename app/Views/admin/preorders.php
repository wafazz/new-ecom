<?php
/** Expects $open (open pre-orders) and $products. */
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Pre-orders</h1>
            <p class="muted">Sell before stock arrives. Pre-order units never touch stock until the goods arrive. When they arrive, record the count: stock becomes what arrived minus the units already sold.</p>
        </div>
    </header>
    <form method="post" action="<?= e(url('/admin/preorders')) ?>" class="msg-card card-form">
        <?= csrf_field() ?>
        <label>Product
            <select name="product_id"><?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></label>
        <label>Expected date (optional) <input type="date" name="expected"></label>
        <label>Most units to pre-order <input name="limit" inputmode="numeric" required placeholder="50"></label>
        <div class="msg-row"><span></span><button class="btn">Open pre-order</button></div>
    </form>
    <section class="msg-card">
        <h2>Open pre-orders</h2>
        <?php if ($open === []): ?>
            <p class="muted">No pre-orders are open.</p>
        <?php else: ?>
            <div class="msg-table-wrap">
                <table class="msg-table">
                    <thead><tr><th>Product</th><th>Expected</th><th>Sold</th><th>Limit</th><th>Stock arrived</th></tr></thead>
                    <tbody>
                        <?php foreach ($open as $o): ?>
                            <tr>
                                <td><?= e($o['name']) ?></td>
                                <td><?= e((string) $o['preorder_expected']) ?></td>
                                <td><?= (int) $o['preorder_sold'] ?></td>
                                <td><?= (int) $o['preorder_limit'] ?></td>
                                <td>
                                    <form method="post" action="<?= e(url('/admin/preorders/arrive')) ?>" class="inline-form" data-swal-confirm="Record the stock as arrived?" data-swal-text="This closes the pre-order and sets stock to the arrived count less the units sold." data-swal-button="Record arrival">
                                        <?= csrf_field() ?><input type="hidden" name="product_id" value="<?= (int) $o['id'] ?>">
                                        <input name="arrived" inputmode="numeric" required placeholder="units" style="width:6rem">
                                        <button class="btn secondary sm">Record</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
