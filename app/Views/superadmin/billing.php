<?php
/** Expects $o (PlatformSettings::overview()) and $errors. Secrets are never shown; a blank secret field keeps the stored one. */
$errors = $errors ?? [];
$mode = $o['billplz_mode'] !== '' ? $o['billplz_mode'] : 'sandbox';
$field = fn(string $k) => $errors[$k] ?? null;
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Payment setup</h1>
            <p class="muted">Where module payments go. Billplz receives card and online payments; the bank account receives transfers that are then checked against an uploaded receipt. These details are separate from the shop's own sales payments.</p>
        </div>
    </header>

    <form method="post" action="<?= e(url('/superadmin/billing')) ?>" class="msg-card card-form" data-busy-form>
        <?= csrf_field() ?>
        <h2>Billplz (for module payments)</h2>
        <label>Mode
            <select name="billplz_mode">
                <option value="sandbox" <?= $mode === 'sandbox' ? 'selected' : '' ?>>Sandbox (testing)</option>
                <option value="production" <?= $mode === 'production' ? 'selected' : '' ?>>Production (live)</option>
            </select>
            <?= $field('billplz_mode') ? '<small class="alert bad">' . e($field('billplz_mode')) . '</small>' : '' ?></label>
        <label>Collection ID
            <input name="billplz_collection_id" value="<?= e($o['billplz_collection_id']) ?>" maxlength="64" autocomplete="off">
            <?= $field('billplz_collection_id') ? '<small class="alert bad">' . e($field('billplz_collection_id')) . '</small>' : '' ?></label>
        <label>API secret key <?= $o['billplz_api_key']['set'] ? '<small class="muted">saved ' . e($o['billplz_api_key']['hint']) . ': leave blank to keep</small>' : '<small class="muted">not set</small>' ?>
            <input type="password" name="billplz_api_key" autocomplete="new-password" placeholder="<?= $o['billplz_api_key']['set'] ? 'unchanged' : '' ?>"></label>
        <label>X-Signature key <?= $o['billplz_x_signature_key']['set'] ? '<small class="muted">saved ' . e($o['billplz_x_signature_key']['hint']) . ': leave blank to keep</small>' : '<small class="muted">not set</small>' ?>
            <input type="password" name="billplz_x_signature_key" autocomplete="new-password" placeholder="<?= $o['billplz_x_signature_key']['set'] ? 'unchanged' : '' ?>"></label>

        <h2>Bank account (for transfers with a receipt)</h2>
        <label>Bank name <input name="bank_name" value="<?= e($o['bank_name']) ?>" maxlength="60"></label>
        <label>Account number <input name="bank_account_number" value="<?= e($o['bank_account_number']) ?>" maxlength="30"></label>
        <label>Account name <input name="bank_account_name" value="<?= e($o['bank_account_name']) ?>" maxlength="120"></label>

        <div class="msg-row"><span></span><button class="btn">Save payment details</button></div>
    </form>
</div>
