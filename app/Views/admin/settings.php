<h1>Settings</h1>
<form method="post" action="<?= e(url('/admin/settings')) ?>" class="card-form" novalidate>
    <?= csrf_field() ?>
    <label class="check"><input type="checkbox" name="guest_checkout_enabled" value="1" <?= $guest ? 'checked' : '' ?>> Allow guest checkout (no account needed)</label>
    <label>Flat shipping fee (<?= e(config('app.currency')) ?>)
        <input name="shipping_flat" inputmode="decimal" value="<?= old('shipping_flat', $shipping) ?>"><?= field_error('shipping_flat') ?></label>
    <label>Default affiliate commission (% of margin)
        <input name="commission_rate" inputmode="decimal" value="<?= old('commission_rate', $rate) ?>"><?= field_error('commission_rate') ?>
        <small class="muted">Margin = selling price − company base price. A product can override this on its edit page.</small></label>
    <button class="btn">Save settings</button>
</form>

<section>
    <h2>Shop logo</h2>
    <p class="muted">Shown in the admin portal, the sign-in pages and the storefront header. Drop an image on the box or click it (JPG, PNG or WebP). Without a logo the first letter of the shop name is shown.</p>
    <form method="post" action="<?= e(url('/admin/settings/logo')) ?>" enctype="multipart/form-data" class="card-form logo-form" data-logo-form novalidate>
        <?= csrf_field() ?>
        <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" data-logo-input>
        <div class="logo-drop" data-logo-drop role="button" tabindex="0" aria-label="Choose shop logo image" data-current="<?= e((string) $logo) ?>">
            <canvas class="logo-canvas" data-logo-canvas aria-hidden="true"></canvas>
            <span class="logo-hint" data-logo-hint><?= $logo ? 'Drop a new logo here, or click to replace it' : 'Drop the logo here, or click to choose' ?></span>
        </div>
        <?= field_error('logo') ?>
        <div class="logo-actions">
            <button class="btn" data-logo-save disabled>Upload logo</button>
        </div>
        <p class="muted logo-note">The image is resized to at most 800 px before upload.</p>
    </form>
    <?php if ($logo): ?>
        <form method="post" action="<?= e(url('/admin/settings/logo/remove')) ?>" class="inline" data-swal-confirm="Remove the shop logo?" data-swal-text="The first letter of the shop name is shown instead." data-swal-button="Yes, remove" data-swal-danger="1">
            <?= csrf_field() ?><button class="btn secondary" type="submit">Remove logo</button>
        </form>
    <?php endif; ?>
</section>
