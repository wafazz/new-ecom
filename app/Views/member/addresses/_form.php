<?php /* expects $action, $a (array of current values), $states, $submit */ ?>
<form method="post" action="<?= e(url($action)) ?>" class="card-form" novalidate>
    <?= csrf_field() ?>
    <label>Label (optional, e.g. Home) <input name="label" maxlength="40" value="<?= old('label', (string) ($a['label'] ?? '')) ?>"><?= field_error('label') ?></label>
    <label>Full name <input name="name" value="<?= old('name', (string) ($a['name'] ?? '')) ?>" autocomplete="name"><?= field_error('name') ?></label>
    <label>Phone <input name="phone" value="<?= old('phone', (string) ($a['phone'] ?? '')) ?>" autocomplete="tel"><?= field_error('phone') ?></label>
    <label>Address line 1 <input name="line1" value="<?= old('line1', (string) ($a['line1'] ?? '')) ?>"><?= field_error('line1') ?></label>
    <label>Address line 2 (optional) <input name="line2" value="<?= old('line2', (string) ($a['line2'] ?? '')) ?>"><?= field_error('line2') ?></label>
    <label>City <input name="city" value="<?= old('city', (string) ($a['city'] ?? '')) ?>"><?= field_error('city') ?></label>
    <label>State
        <select name="state">
            <option value="">Select…</option>
            <?php foreach ($states as $s): ?><option <?= old('state', (string) ($a['state'] ?? '')) === e($s) ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
        </select><?= field_error('state') ?></label>
    <label>Postcode <input name="postcode" inputmode="numeric" maxlength="5" value="<?= old('postcode', (string) ($a['postcode'] ?? '')) ?>"><?= field_error('postcode') ?></label>
    <?php if (!empty($withDefault)): ?><label class="check"><input type="checkbox" name="is_default" value="1"> Make this my default address</label><?php endif; ?>
    <button class="btn"><?= e($submit) ?></button>
</form>
