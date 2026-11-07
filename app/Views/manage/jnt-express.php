<?php
$haveCreds = $cfg['complete'];
$chip = $haveCreds ? ['ok', 'Saved'] : ['', 'Not configured'];
$keyHint = $cfg['private_key_set'] ? ($cfg['private_key_hint'] !== null ? "Saved ({$cfg['private_key_hint']}). Leave blank to keep it." : 'Saved but unreadable (APP_KEY changed). Enter it again.') : 'Not set yet.';
$pwHint = $cfg['password_set'] ? ($cfg['password_hint'] !== null ? "Saved ({$cfg['password_hint']}). Leave blank to keep it." : 'Saved but unreadable (APP_KEY changed). Enter it again.') : 'Not set yet.';
$v = fn(string $f) => old($f, $cfg[$f]);
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>J&amp;T Express</h1>
            <p class="muted">Courier account details for J&amp;T Express Malaysia. The Private Key and Password are stored encrypted and are never shown again after saving.</p>
        </div>
        <div class="msg-row">
            <span class="msg-pill <?= e($chip[0]) ?>">Credentials: <?= e($chip[1]) ?></span>
            <span class="msg-pill <?= $cfg['enabled'] ? 'ok' : '' ?>">J&amp;T: <?= $cfg['enabled'] ? 'Enabled' : 'Off' ?></span>
        </div>
    </header>

    <?php if ($cfg['unreadable']): ?><div class="alert bad">A saved secret can't be decrypted (APP_KEY was changed). Enter it again.</div><?php endif; ?>

    <section class="msg-card">
        <h2>API credentials</h2>
        <form method="post" action="<?= e(url($base . '/shipping/jnt-express')) ?>" class="card-form wide" autocomplete="off" novalidate data-busy-form>
            <?= csrf_field() ?>
            <fieldset>
                <legend>J&amp;T account</legend>
                <label class="check"><input type="checkbox" name="enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>> Enable J&amp;T Express (shows "Push to J&amp;T Express" on Processing orders)</label>
                <label>Mode
                    <select name="mode">
                        <?php foreach ($modes as $k => $label): ?><option value="<?= e($k) ?>" <?= $v('mode') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select><?= field_error('mode') ?></label>
                <div class="grid2">
                    <label>API account <input name="api_account" value="<?= $v('api_account') ?>" maxlength="100" spellcheck="false"><?= field_error('api_account') ?></label>
                    <label>Customer code <input name="customer_code" value="<?= $v('customer_code') ?>" maxlength="100" spellcheck="false"><?= field_error('customer_code') ?></label>
                </div>
                <label>Private Key <input type="password" name="private_key" autocomplete="new-password" spellcheck="false" maxlength="200" placeholder="<?= e($keyHint) ?>"><?= field_error('private_key') ?></label>
                <label>Password <input type="password" name="password" autocomplete="new-password" spellcheck="false" maxlength="200" placeholder="<?= e($pwHint) ?>"><?= field_error('password') ?></label>
                <small class="muted">Issued by J&amp;T for your account (ask your J&amp;T account manager or distribution partner). Use the sandbox details while testing and switch the mode to production only when J&amp;T has activated your live account.</small>
            </fieldset>
            <div class="msg-row"><span></span><button class="btn">Save</button></div>
        </form>
    </section>

    <section class="msg-card">
        <h2>Pickup address</h2>
        <p class="muted">J&amp;T uses the pickup (sender) address saved in <a href="<?= e(url($base . '/shipping/ninjavan')) ?>">NinjaVan settings</a>. Fill it in there before pushing orders.</p>
    </section>

    <?php if ($history !== []): ?>
    <section class="msg-card">
        <h2>Recent changes</h2>
        <div class="table-wrap">
        <table class="msg-table">
            <thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr><td><small><?= e($h['created_at']) ?></small></td><td><?= e($h['actor'] ?? 'removed user') ?></td><td><?= e($h['detail'] ?? $h['action']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endif; ?>
</div>
