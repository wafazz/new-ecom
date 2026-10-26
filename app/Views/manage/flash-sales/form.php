<?php
/**
 * Expects $base, $sale (null when new), $fields (name, starts_at, ends_at as typed), $picked ([product_id => ['percent']]),
 * $errors (field => message), $products (FlashSaleService::selectable()), $max.
 */
$action = $sale !== null ? url($base . '/flash-sales/' . (int) $sale['id']) : url($base . '/flash-sales');
$previewUrl = url($base . '/flash-sales/preview');
$err = fn(string $key) => isset($errors[$key]) ? '<small class="error">' . e($errors[$key]) . '</small>' : '';
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1><?= e($title) ?></h1>
            <p class="muted">Tick the products, give each a percentage off, and set the timeframe. The preview on the right shows the storefront block as it will look while the sale is live.</p>
        </div>
        <div class="row-between">
            <a class="btn secondary" href="<?= e(url($base . '/flash-sales')) ?>">Back to flash sales</a>
        </div>
    </header>

    <?php if ($errors !== []): ?>
        <p class="msg-pill bad" role="alert" style="margin:.5rem 0">Please fix the highlighted fields and save again.</p>
    <?php endif; ?>

    <form method="post" action="<?= e($action) ?>" novalidate class="flash-editor" data-flash-editor data-preview-url="<?= e($previewUrl) ?>">
        <?= csrf_field() ?>

        <div class="flash-editor-main">
            <section class="msg-card">
                <h2>Timeframe</h2>
                <div class="grid-form">
                    <label>Name
                        <input name="name" value="<?= e($fields['name']) ?>" maxlength="120" required placeholder="Weekend flash sale">
                        <?= $err('name') ?>
                    </label>
                    <label>Starts
                        <input type="datetime-local" name="starts_at" value="<?= e($fields['starts_at']) ?>" required>
                        <?= $err('starts_at') ?>
                    </label>
                    <label>Ends
                        <input type="datetime-local" name="ends_at" value="<?= e($fields['ends_at']) ?>" required>
                        <?= $err('ends_at') ?>
                    </label>
                </div>
                <p class="muted" style="margin:.5rem 0 0"><small>Times are in the shop's time zone. Several sales can be scheduled at different times.</small></p>
            </section>

            <section class="msg-card" style="margin-top:1rem">
                <div class="row-between">
                    <h2>Products</h2>
                    <input type="search" data-flash-search placeholder="Search products" aria-label="Search products" style="max-width:240px">
                </div>
                <?= $err('items') ?>
                <?php if ($products === []): ?>
                    <p class="msg-empty" style="margin-top:.6rem">No live products yet. Publish a product first, then come back here.</p>
                <?php else: ?>
                <div class="table-wrap" style="margin-top:.6rem">
                <table class="msg-table flash-picks">
                    <thead><tr><th style="width:2.5rem"><span class="sr-only">Include</span></th><th>Product</th><th>Price now</th><th style="width:8rem">% off</th></tr></thead>
                    <tbody>
                    <?php foreach ($products as $p):
                        $pid = (int) $p['id'];
                        $on = isset($picked[$pid]);
                        $live = $p['discount_price'] !== null && (float) $p['discount_price'] < (float) $p['retail_price'];
                    ?>
                        <tr data-flash-row data-name="<?= e(mb_strtolower((string) $p['name'] . ' ' . (string) $p['sku'])) ?>">
                            <td><input type="checkbox" name="items[<?= $pid ?>][on]" value="1" <?= $on ? 'checked' : '' ?> aria-label="Include <?= e($p['name']) ?>" data-flash-pick></td>
                            <td>
                                <strong><?= e($p['name']) ?></strong>
                                <?php if ((int) $p['variant_count'] > 0): ?><br><small class="muted">Has options: the % applies to every option.</small><?php endif; ?>
                                <?php if ($p['status'] !== 'active'): ?><br><small class="muted">Draft: will not show until published.</small><?php endif; ?>
                                <?= $err("item_{$pid}") ?>
                            </td>
                            <td><small><?= e(money((float) ($live ? $p['discount_price'] : $p['retail_price']))) ?></small></td>
                            <td>
                                <input type="number" name="items[<?= $pid ?>][percent]" value="<?= e($picked[$pid]['percent'] ?? '') ?>" min="1" max="<?= (int) $max ?>" step="0.01" inputmode="decimal" placeholder="e.g. 20" aria-label="Percent off for <?= e($p['name']) ?>" style="width:6.5rem">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p class="muted" style="margin:.5rem 0 0"><small>Up to <?= (int) $max ?>%. The percentage is taken off the normal price (or the discount price, if the product has one running). Stock is not held back: the sale ends when the timeframe does.</small></p>
                <?php endif; ?>
            </section>

            <div class="row-between" style="margin-top:1rem">
                <a class="btn secondary" href="<?= e(url($base . '/flash-sales')) ?>">Cancel</a>
                <button class="btn" type="submit"><?= $sale !== null ? 'Save changes' : 'Create flash sale' ?></button>
            </div>
        </div>

        <aside class="flash-editor-preview" aria-label="Storefront preview">
            <section class="msg-card">
                <div class="row-between">
                    <h2>Live preview</h2>
                    <span class="msg-pill info" data-flash-preview-state>Updates as you type</span>
                </div>
                <p class="muted" style="margin:.4rem 0 .8rem"><small>How the storefront block looks while the sale is running. Nothing is saved until you press save.</small></p>
                <iframe class="flash-preview-frame" data-flash-preview title="Storefront preview of this flash sale" sandbox="allow-scripts"></iframe>
            </section>
        </aside>
    </form>
</div>
