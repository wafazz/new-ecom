<?php
/**
 * The three things we ask after a bank transfer: the receipt, the bank paid from and when. Shared by checkout and the order page.
 * Field names: receipt (file), proof_bank, proof_paid_at. Expects nothing; reads old input when a form is redisplayed.
 */
$__oldBank = old('proof_bank');
$__oldWhen = old('proof_paid_at');
$__groups = \App\Services\Banks::groups();
$__known = \App\Services\Banks::all();
?>
<div class="proof-fields" data-proof-fields>
    <label class="proof-drop" data-drop>
        <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" data-receipt aria-describedby="receipt-help">
        <span class="proof-drop-ui" data-drop-ui>
            <?= icon('box', 'i i-lg') ?>
            <strong>Upload your payment receipt</strong>
            <small id="receipt-help">Drop it here or tap to choose · photo, screenshot or PDF · up to 5 MB</small>
        </span>
        <span class="proof-preview" data-preview hidden></span>
    </label>
    <?= field_error('receipt') ?>
    <small class="error js-error" data-receipt-error hidden></small>

    <div class="grid2 proof-row">
        <label>Bank you paid from
            <select name="proof_bank" data-bank-select data-placeholder="Choose or type a bank…">
                <option value=""></option>
                <?php foreach ($__groups as $__g => $__banks): ?>
                    <optgroup label="<?= e($__g) ?>">
                        <?php foreach ($__banks as $__b): ?><option value="<?= e($__b) ?>" <?= $__oldBank === e($__b) ? 'selected' : '' ?>><?= e($__b) ?></option><?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
                <?php if ($__oldBank !== '' && !in_array(html_entity_decode($__oldBank, ENT_QUOTES), $__known, true)): ?><option value="<?= $__oldBank ?>" selected><?= $__oldBank ?></option><?php endif; ?>
            </select>
            <?= field_error('proof_bank') ?></label>
        <label>Date and time you paid
            <input type="datetime-local" name="proof_paid_at" value="<?= $__oldWhen ?>" data-paid-at>
            <?= field_error('proof_paid_at') ?></label>
    </div>
</div>
