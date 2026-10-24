<?php
$haveCreds = $cfg['client_id'] !== '' && $cfg['client_secret_set'];
$state = !$haveCreds ? 'none' : ($cfg['verified'] === 'ok' ? 'ok' : ($cfg['verified'] === 'fail' ? 'fail' : 'saved'));
$chip = ['none' => ['draft', 'Not configured'], 'saved' => ['sale', 'Saved, not tested'], 'ok' => ['active', 'Connected'], 'fail' => ['st-cancelled', 'Test failed']][$state];
$secretHint = $cfg['client_secret_set'] ? ($cfg['client_secret_hint'] !== null ? "Saved ({$cfg['client_secret_hint']}). Leave blank to keep it." : 'Saved but unreadable (APP_KEY changed). Enter it again.') : 'Not set yet.';
$v = fn(string $f) => old($f, $cfg[$f]);
?>
<h1>NinjaVan</h1>
<p class="muted">Courier credentials and your pickup address. The Client Key is stored encrypted and is never shown again after saving.</p>

<p>Credentials: <span class="badge <?= e($chip[0]) ?>"><?= e($chip[1]) ?></span>
    <?php if ($cfg['verified_at'] !== ''): ?><small class="muted"> · last test <?= e($cfg['verified_at']) ?> — <?= e($cfg['verified_msg']) ?></small><?php endif; ?>
    &nbsp; Pickup address: <span class="badge <?= $cfg['sender_complete'] ? 'active' : 'draft' ?>"><?= $cfg['sender_complete'] ? 'Complete' : 'Not set' ?></span></p>
<?php if ($cfg['unreadable']): ?><div class="alert bad">The saved Client Key can't be decrypted (APP_KEY was changed). Enter it again.</div><?php endif; ?>

<form method="post" action="<?= e(url($base . '/shipping/ninjavan')) ?>" class="card-form wide" autocomplete="off" novalidate>
    <?= csrf_field() ?>
    <fieldset>
        <legend>API credentials</legend>
        <div class="grid2">
            <label>Mode
                <select name="mode">
                    <?php foreach ($modes as $k => $label): ?><option value="<?= e($k) ?>" <?= $v('mode') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                </select><?= field_error('mode') ?></label>
            <label>Country
                <select name="country">
                    <?php foreach ($countries as $k => $label): ?><option value="<?= e($k) ?>" <?= $v('country') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                </select><?= field_error('country') ?></label>
        </div>
        <label>Client ID <input name="client_id" value="<?= $v('client_id') ?>" maxlength="100" spellcheck="false"><?= field_error('client_id') ?></label>
        <label>Client Key <input type="password" name="client_secret" autocomplete="new-password" spellcheck="false" maxlength="200" placeholder="<?= e($secretHint) ?>"><?= field_error('client_secret') ?></label>
        <small class="muted">From your Ninja Dashboard (shown there as Client ID and Client Key). Sandbox and production use different credentials; changing the mode, country or Client ID asks for the Client Key again.</small>
    </fieldset>

    <fieldset>
        <legend>Pickup (sender) address</legend>
        <small class="muted">Used as the "from" address on every shipment. Leave all blank for now if you only want to save the credentials.</small>
        <div class="grid2">
            <label>Sender name <input name="sender_name" value="<?= $v('sender_name') ?>" maxlength="120"><?= field_error('sender_name') ?></label>
            <label>Sender phone <input name="sender_phone" value="<?= $v('sender_phone') ?>" maxlength="30"><?= field_error('sender_phone') ?></label>
        </div>
        <label>Sender email <input type="email" name="sender_email" value="<?= $v('sender_email') ?>" maxlength="190"><?= field_error('sender_email') ?></label>
        <label>Address line 1 <input name="sender_address1" value="<?= $v('sender_address1') ?>" maxlength="190"><?= field_error('sender_address1') ?></label>
        <label>Address line 2 (optional) <input name="sender_address2" value="<?= $v('sender_address2') ?>" maxlength="190"><?= field_error('sender_address2') ?></label>
        <div class="grid3">
            <label>City <input name="sender_city" value="<?= $v('sender_city') ?>" maxlength="100"><?= field_error('sender_city') ?></label>
            <label>State
                <input name="sender_state" value="<?= $v('sender_state') ?>" maxlength="60" list="my-states" autocomplete="off">
                <datalist id="my-states"><?php foreach ($states as $st): ?><option value="<?= e($st) ?>"><?php endforeach; ?></datalist>
                <?= field_error('sender_state') ?></label>
            <label>Postcode <input name="sender_postcode" value="<?= $v('sender_postcode') ?>" maxlength="10"><?= field_error('sender_postcode') ?></label>
        </div>
    </fieldset>
    <fieldset>
        <legend>AWB label</legend>
        <label class="check"><input type="checkbox" name="awb_show_items" value="1" <?= $cfg['awb_show_items'] ? 'checked' : '' ?>> Show the items ordered on the AWB</label>
        <p class="muted">Off: the label shows only the order number, weight and addresses.</p>
    </fieldset>
    <button class="btn">Save</button>
</form>

<section>
    <h2>Tracking webhook</h2>
    <p class="muted">Register this URL in your Ninja Dashboard (webhook settings) to receive tracking updates on each order. NinjaVan signs every request with your Client Key; unsigned requests are rejected.</p>
    <p><input readonly value="<?= e(url('/shipping/ninjavan/webhook')) ?>" onfocus="this.select()" style="width:100%;max-width:560px"></p>
</section>

<section>
    <h2>Test connection</h2>
    <form method="post" action="<?= e(url($base . '/shipping/ninjavan/test')) ?>" class="inline"><?= csrf_field() ?>
        <button class="btn secondary" <?= $haveCreds ? '' : 'disabled' ?>>Test with saved credentials</button></form>
    <small class="muted">Asks NinjaVan for an access token with the saved Client ID and Key. No shipment is created.</small>
</section>

<?php if ($history !== []): ?>
<section>
    <h2>Recent changes</h2>
    <table>
        <thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr><td><?= e($h['created_at']) ?></td><td><?= e($h['actor'] ?? 'removed user') ?></td><td><?= e($h['detail'] ?? $h['action']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>
