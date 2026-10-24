<?php
$state = !$cfg['api_key_set'] || !$cfg['x_signature_key_set'] || $cfg['collection_id'] === '' ? 'none' : ($cfg['verified'] === 'ok' ? 'ok' : ($cfg['verified'] === 'fail' ? 'fail' : 'saved'));
$chip = ['none' => ['draft', 'Not configured'], 'saved' => ['sale', 'Saved, not tested'], 'ok' => ['active', 'Connected'], 'fail' => ['st-cancelled', 'Test failed']][$state];
$hint = fn(string $f, string $label) => $cfg[$f . '_set']
    ? ($cfg[$f . '_hint'] !== null ? "Saved ({$cfg[$f . '_hint']}). Leave blank to keep it." : 'Saved but unreadable (APP_KEY changed). Enter it again.')
    : 'Not set yet.';
?>
<h1>Billplz</h1>
<p class="muted">Payment gateway credentials. Keys are stored encrypted and are never shown again after saving.</p>

<p>Status: <span class="badge <?= e($chip[0]) ?>"><?= e($chip[1]) ?></span>
    <?php if ($cfg['verified_at'] !== ''): ?><small class="muted"> · last test <?= e($cfg['verified_at']) ?> — <?= e($cfg['verified_msg']) ?></small><?php endif; ?></p>
<p>At checkout:
    <?php if ($state === 'ok'): ?><span class="badge active">Offered to customers</span>
    <?php else: ?><span class="badge draft">Hidden</span> <small class="muted">— shown to customers only after a passing "Test connection". Changing any detail hides it again until re-tested.</small><?php endif; ?></p>
<?php $host = strtolower((string) parse_url((string) env('APP_URL', ''), PHP_URL_HOST)); if (in_array($host, ['localhost', '127.0.0.1', '::1', ''], true) || str_ends_with($host, '.test') || str_ends_with($host, '.localhost')): ?>
    <div class="alert bad">APP_URL (<?= e((string) env('APP_URL', '')) ?>) is a local address. Billplz can't send payment confirmations to it, so payments will not be marked paid automatically. Use a public https URL (a staging domain or a tunnel) to go live.</div>
<?php endif; ?>
<?php if ($cfg['unreadable']): ?><div class="alert bad">A saved key can't be decrypted (APP_KEY was changed). Re-enter both keys.</div><?php endif; ?>

<form method="post" action="<?= e(url($base . '/payments/billplz')) ?>" class="card-form" autocomplete="off" novalidate>
    <?= csrf_field() ?>
    <label>Mode
        <select name="mode">
            <?php foreach ($modes as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= old('mode', $cfg['mode']) === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select><?= field_error('mode') ?>
        <small class="muted">Sandbox and production use different keys. Switching mode asks you to re-enter them.</small></label>
    <label>Collection ID <input name="collection_id" value="<?= old('collection_id', $cfg['collection_id']) ?>" maxlength="64" spellcheck="false"><?= field_error('collection_id') ?></label>
    <label>Payment order collection ID <input name="payment_order_collection_id" value="<?= old('payment_order_collection_id', $cfg['payment_order_collection_id']) ?>" maxlength="64" spellcheck="false"><small class="muted">Used for paying affiliate withdrawals. Leave empty until payouts are set up.</small><?= field_error('payment_order_collection_id') ?></label>
    <label>API secret key <input type="password" name="api_key" autocomplete="new-password" spellcheck="false" maxlength="200" placeholder="<?= e($hint('api_key', 'API secret key')) ?>"><?= field_error('api_key') ?></label>
    <label>X-Signature key <input type="password" name="x_signature_key" autocomplete="new-password" spellcheck="false" maxlength="200" placeholder="<?= e($hint('x_signature_key', 'X-Signature key')) ?>"><?= field_error('x_signature_key') ?></label>
    <button class="btn">Save</button>
    <small class="muted">Find these in your Billplz dashboard: Settings → Keys &amp; Integration (API secret key, X-Signature key) and Billing → Collections (Collection ID).</small>
</form>

<section>
    <h2>Test connection</h2>
    <form method="post" action="<?= e(url($base . '/payments/billplz/test')) ?>" class="inline"><?= csrf_field() ?>
        <button class="btn secondary" <?= $state === 'none' ? 'disabled' : '' ?>>Test with saved details</button></form>
    <small class="muted">Asks Billplz for your collection using the saved key. Nothing is charged or created.</small>
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
