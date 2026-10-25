<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Settings</h1>
            <p class="muted">Shipping, commission and checkout options for the whole shop. Changes apply to new orders straight away.</p>
        </div>
    </header>

    <section class="msg-card">
        <h2>Checkout and money</h2>
        <form method="post" action="<?= e(url('/admin/settings')) ?>" class="card-form" novalidate data-busy-form>
            <?= csrf_field() ?>
            <label class="check"><input type="checkbox" name="guest_checkout_enabled" value="1" <?= $guest ? 'checked' : '' ?>> Allow guest checkout (no account needed)</label>
            <p class="muted">Postage is no longer a flat fee. It is worked out from weight and zone; set the rates under <a href="<?= e(url('/admin/shipping-rates')) ?>">Shipping rates</a>.</p>
            <label>Default affiliate commission (% of sales after shipping)
                <input name="commission_rate" inputmode="decimal" value="<?= old('commission_rate', $rate) ?>"><?= field_error('commission_rate') ?>
                <small class="muted">Commission = (line sales − its share of shipping) × this %. A product can override this on its edit page.</small></label>
            <div class="msg-row"><span></span><button class="btn">Save settings</button></div>
        </form>
    </section>

    <section class="msg-card">
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
</div>
