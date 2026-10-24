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
