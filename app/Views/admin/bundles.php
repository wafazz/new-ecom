<?php
/** Expects $bundles (id, name, stock, parts) and $products (choices for the bundle and its items). */
$options = fn(int $selected = 0) => implode('', array_map(fn($p) => '<option value="' . (int) $p['id'] . '"' . ((int) $p['id'] === $selected ? ' selected' : '') . '>' . e($p['name']) . '</option>', $products));
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Bundles</h1>
            <p class="muted">A bundle is a product made of other single products, sold at one price. Its stock is how many bundles the items can make. Sales take stock from each item.</p>
        </div>
    </header>

    <form method="post" action="<?= e(url('/admin/bundles')) ?>" class="msg-card card-form">
        <?= csrf_field() ?>
        <label>Bundle product
            <select name="bundle_id" required><option value="">Choose…</option><?= $options() ?></select></label>
        <p class="muted">Items in the bundle (leave blank rows empty; up to 10 items):</p>
        <?php for ($i = 0; $i < 10; $i++): ?>
            <div class="bundle-row">
                <select name="part[]" aria-label="Item <?= $i + 1 ?>"><option value="">—</option><?= $options() ?></select>
                <input name="qty[]" inputmode="numeric" value="1" aria-label="Quantity for item <?= $i + 1 ?>" style="width:5rem">
            </div>
        <?php endfor; ?>
        <div class="msg-row"><span></span><button class="btn">Save bundle</button></div>
    </form>

    <section class="msg-card">
        <h2>Bundles</h2>
        <?php if ($bundles === []): ?>
            <p class="muted">No bundles yet.</p>
        <?php else: ?>
            <div class="msg-table-wrap">
                <table class="msg-table">
                    <thead><tr><th>Bundle</th><th>Items</th><th>Can make now</th></tr></thead>
                    <tbody>
                        <?php foreach ($bundles as $b): ?>
                            <tr>
                                <td><?= e($b['name']) ?></td>
                                <td><?= e(implode(', ', array_map(fn($p) => $p['name'] . ' × ' . $p['qty'] . ($p['usable'] ? '' : ' (not for sale)'), $b['parts']))) ?></td>
                                <td><?= (int) $b['stock'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
