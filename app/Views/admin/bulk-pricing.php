<?php
/** Expects $preview (null, or scope, percent, rows), $categories, $brands, $batches. */
$scope = $preview['scope'] ?? ['type' => 'all', 'id' => 0];
$percent = $preview['percent'] ?? '';
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Bulk prices</h1>
            <p class="muted">Change regular prices by a percentage. Sale prices are not changed. Preview first, then apply. Each change can be undone from the list below.</p>
        </div>
    </header>

    <form method="post" action="<?= e(url('/admin/bulk-pricing/preview')) ?>" class="msg-card card-form">
        <?= csrf_field() ?>
        <label>Which products
            <select name="scope" data-bulk-scope>
                <option value="all" <?= $scope['type'] === 'all' ? 'selected' : '' ?>>All products</option>
                <option value="category" <?= $scope['type'] === 'category' ? 'selected' : '' ?>>A category (and its sub-categories)</option>
                <option value="brand" <?= $scope['type'] === 'brand' ? 'selected' : '' ?>>A brand</option>
            </select></label>
        <label>Category
            <select name="scope_id"><option value="0">Choose…</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $scope['type'] === 'category' && (int) $scope['id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
        <label>Brand
            <select name="brand_id" aria-label="Brand"><option value="0">Choose…</option><?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $scope['type'] === 'brand' && (int) $scope['id'] === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></label>
        <label>Change by (%)
            <input name="percent" inputmode="decimal" value="<?= e((string) $percent) ?>" placeholder="for example 10 or -5">
            <small class="muted">Positive raises prices, negative lowers them. From -90 to 200.</small></label>
        <div class="msg-row"><span></span><button class="btn">Preview changes</button></div>
    </form>

    <?php if ($preview !== null): ?>
        <section class="msg-card">
            <h2>Preview: <?= count($preview['rows']) ?> price(s) would change</h2>
            <?php if ($preview['rows'] === []): ?>
                <p class="muted">No prices would change for this choice.</p>
            <?php else: ?>
                <div class="msg-table-wrap">
                    <table class="msg-table">
                        <thead><tr><th>Product</th><th>Option</th><th>Now</th><th>New</th></tr></thead>
                        <tbody>
                            <?php foreach ($preview['rows'] as $row): ?>
                                <tr><td><?= e($row['name']) ?></td><td><?= e($row['label']) ?></td><td>RM <?= e($row['old']) ?></td><td>RM <?= e($row['new']) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <form method="post" action="<?= e(url('/admin/bulk-pricing')) ?>" class="msg-row" data-swal-confirm="Apply these prices?" data-swal-text="This changes the shop prices now. You can undo it from the list below." data-swal-button="Apply prices">
                    <?= csrf_field() ?>
                    <input type="hidden" name="scope" value="<?= e($scope['type']) ?>">
                    <input type="hidden" name="scope_id" value="<?= (int) $scope['id'] ?>">
                    <input type="hidden" name="percent" value="<?= e((string) $percent) ?>">
                    <span class="muted">Applies to <?= count($preview['rows']) ?> price(s).</span>
                    <button class="btn">Apply prices</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="msg-card">
        <h2>Recent changes</h2>
        <?php if ($batches === []): ?>
            <p class="muted">No bulk price changes yet.</p>
        <?php else: ?>
            <div class="msg-table-wrap">
                <table class="msg-table">
                    <thead><tr><th>When</th><th>Who</th><th>Scope</th><th>Change</th><th>Products</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($batches as $b): ?>
                            <tr>
                                <td><?= e(date('d M Y, H:i', strtotime((string) $b['created_at']))) ?></td>
                                <td><?= e((string) ($b['actor'] ?? 'Unknown')) ?></td>
                                <td><?= e($b['scope_label']) ?></td>
                                <td><?= e(rtrim(rtrim(number_format((float) $b['percent'], 2, '.', ''), '0'), '.')) ?>%</td>
                                <td><?= (int) $b['item_count'] ?></td>
                                <td>
                                    <?php if ($b['undone_at'] !== null): ?><span class="muted">Undone</span>
                                    <?php else: ?>
                                        <form method="post" action="<?= e(url('/admin/bulk-pricing/' . (int) $b['id'] . '/undo')) ?>" data-swal-confirm="Undo this price change?" data-swal-text="Prices that have been changed again since are left as they are." data-swal-button="Undo" data-swal-danger>
                                            <?= csrf_field() ?><button class="btn secondary sm">Undo</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
