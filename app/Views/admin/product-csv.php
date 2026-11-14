<?php
/** Expects $checked (null, or the result of ProductCsvService::check) and $headers. */
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Import and export</h1>
            <p class="muted">Update regular prices and stock for many products at once, matched by SKU. Export the list, edit it in a spreadsheet, save as CSV, and upload it here. Blank cells are left unchanged.</p>
        </div>
        <a class="btn secondary" href="<?= e(url('/admin/products/csv/export')) ?>">Export all products (CSV)</a>
    </header>

    <section class="msg-card">
        <h2>Upload a file</h2>
        <p class="muted">Columns: <code>sku</code>, <code>retail_price</code>, <code>stock</code>. The first row must be these headers. Up to <?= (int) \App\Services\ProductCsvService::MAX_ROWS ?> rows, 512 KB.</p>
        <form method="post" action="<?= e(url('/admin/products/csv/check')) ?>" enctype="multipart/form-data" class="card-form">
            <?= csrf_field() ?>
            <label>CSV file <input type="file" name="csv" accept=".csv,text/csv" required></label>
            <div class="msg-row"><span></span><button class="btn">Check file</button></div>
        </form>
    </section>

    <?php if ($checked !== null): ?>
        <section class="msg-card">
            <?php if ($checked['fatal'] !== null): ?>
                <p class="alert bad"><?= e($checked['fatal']) ?></p>
            <?php else: ?>
                <h2><?= (int) $checked['ok'] ?> row(s) ready · <?= (int) $checked['bad'] ?> with errors</h2>
                <p class="muted">Rows with errors are skipped when you apply. The rest are applied together.</p>
                <div class="msg-table-wrap">
                    <table class="msg-table">
                        <thead><tr><th>Line</th><th>SKU</th><th>Result</th></tr></thead>
                        <tbody>
                            <?php foreach ($checked['rows'] as $r): ?>
                                <tr>
                                    <td><?= (int) $r['line'] ?></td>
                                    <td><?= e($r['sku']) ?></td>
                                    <td><?php if ($r['ok']): ?>
                                        <?= $r['price'] !== null ? 'Price RM ' . e($r['price']) . ' ' : '' ?><?= $r['stock'] !== null ? 'Stock ' . (int) $r['stock'] : '' ?>
                                    <?php else: ?><span class="muted"><?= e((string) $r['error']) ?></span><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($checked['ok'] > 0): ?>
                    <div class="msg-row">
                        <form method="post" action="<?= e(url('/admin/products/csv/cancel')) ?>"><?= csrf_field() ?><button class="btn secondary">Cancel</button></form>
                        <form method="post" action="<?= e(url('/admin/products/csv/apply')) ?>" data-swal-confirm="Apply <?= (int) $checked['ok'] ?> row(s)?" data-swal-text="This changes live prices and stock now. Stock changes are recorded in the stock movement log." data-swal-button="Apply"><?= csrf_field() ?><button class="btn">Apply <?= (int) $checked['ok'] ?> row(s)</button></form>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
