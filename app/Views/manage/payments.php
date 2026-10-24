<?php
/** Expects: $base, $cod, $bank, $bankOn, $billplzOn, $billplzReady, $offered */
$v = fn(string $k, string $current) => old($k, $current);
$checked = fn(string $k, bool $current) => (isset($_SESSION['_old']) ? (($_SESSION['_old'][$k] ?? '') === '1') : $current) ? ' checked' : '';
?>
<h1>Payments</h1>
<p class="muted">Choose how customers can pay and what they are told. Orders already placed keep the method they chose.</p>

<p class="pay-offered" id="pay-offered"><strong>Customers can pay by:</strong>
    <?php if ($offered === []): ?><span class="badge st-cancelled">nothing right now</span>
    <?php else: foreach ($offered as $label): ?><span class="badge active"><?= e($label) ?></span> <?php endforeach; endif; ?>
</p>

<form method="post" action="<?= e(url($base . '/payments')) ?>" class="pay-settings" data-payments novalidate>
    <?= csrf_field() ?>

    <section class="panel pay-card" data-method="cod">
        <header class="pay-head">
            <div><h2>Cash on delivery</h2><p class="muted">The customer pays the courier in cash when the parcel arrives.</p></div>
            <label class="toggle"><input type="checkbox" name="cod_enabled" value="1"<?= $checked('cod_enabled', $cod['on']) ?> data-toggle><span class="toggle-ui" aria-hidden="true"></span><span class="toggle-text" data-toggle-text></span></label>
        </header>
        <label>Message shown to customers <small class="muted">(optional)</small>
            <input name="cod_note" value="<?= $v('cod_note', $cod['note']) ?>" maxlength="255" placeholder="<?= e(\App\Services\PaymentSettings::COD_DEFAULT_NOTE) ?>"><?= field_error('cod_note') ?></label>
    </section>

    <section class="panel pay-card" data-method="bank">
        <header class="pay-head">
            <div><h2>Bank transfer</h2><p class="muted">The customer transfers the money to your bank account and you mark the order paid.</p></div>
            <label class="toggle"><input type="checkbox" name="bank_enabled" value="1"<?= $checked('bank_enabled', $bankOn) ?> data-toggle><span class="toggle-ui" aria-hidden="true"></span><span class="toggle-text" data-toggle-text></span></label>
        </header>
        <div class="grid2">
            <?php $curBank = html_entity_decode($v('bank_name', $bank['name']), ENT_QUOTES); ?>
            <label>Bank name
                <select name="bank_name" data-bank="name" data-bank-select data-placeholder="Choose or type a bank…">
                    <option value=""></option>
                    <?php foreach (\App\Services\Banks::groups() as $g => $names): ?>
                        <optgroup label="<?= e($g) ?>"><?php foreach ($names as $n): ?><option value="<?= e($n) ?>"<?= $curBank === $n ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></optgroup>
                    <?php endforeach; ?>
                    <?php if ($curBank !== '' && !\App\Services\Banks::known($curBank)): ?><option value="<?= e($curBank) ?>" selected><?= e($curBank) ?></option><?php endif; ?>
                </select><?= field_error('bank_name') ?></label>
            <label>Account number <input name="bank_account_no" value="<?= $v('bank_account_no', $bank['number']) ?>" maxlength="30" inputmode="numeric" placeholder="e.g. 5123 4567 8901" autocomplete="off" data-bank="number"><?= field_error('bank_account_no') ?></label>
        </div>
        <label>Name on the account <input name="bank_account_holder" value="<?= $v('bank_account_holder', $bank['holder']) ?>" maxlength="120" placeholder="e.g. Your Company Sdn Bhd" autocomplete="off" data-bank="holder"><?= field_error('bank_account_holder') ?></label>
        <label>Extra note <small class="muted">(optional)</small>
            <input name="bank_note" value="<?= $v('bank_note', $bank['note']) ?>" maxlength="300" placeholder="e.g. Send the receipt to payments@yourshop.com" data-bank="note"><?= field_error('bank_note') ?></label>
        <div class="bank-preview" aria-live="polite">
            <span class="bank-preview-label">What customers see</span>
            <p id="bank-preview" data-preview></p>
        </div>
    </section>

    <section class="panel pay-card" data-method="billplz">
        <header class="pay-head">
            <div><h2>Online banking / card (Billplz)</h2>
                <p class="muted"><?php if ($billplzReady): ?><span class="badge active">Connected</span> Customers pay on Billplz's secure page (FPX or card) and the order is marked paid automatically.
                <?php else: ?><span class="badge draft">Not connected</span> Add your Billplz keys and pass "Test connection" first; until then it is not offered.<?php endif; ?></p>
                <p><a href="<?= e(url($base . '/payments/billplz')) ?>"><?= $billplzReady ? 'Billplz settings' : 'Set up Billplz' ?> →</a></p></div>
            <label class="toggle"><input type="checkbox" name="billplz_enabled" value="1"<?= $checked('billplz_enabled', $billplzOn) ?> data-toggle><span class="toggle-ui" aria-hidden="true"></span><span class="toggle-text" data-toggle-text></span></label>
        </header>
    </section>

    <p class="pay-actions"><button class="btn">Save payment settings</button></p>
</form>
