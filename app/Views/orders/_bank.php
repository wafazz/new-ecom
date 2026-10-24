<?php
/** The bank account for an unpaid Bank transfer order. Expects $payment and $order. Falls back to plain instructions. */
$__bank = $payment instanceof \App\Services\Payment\BankTransfer ? \App\Services\PaymentSettings::bank() : null;
?>
<?php if ($__bank && \App\Services\PaymentSettings::bankComplete($__bank)): ?>
    <div class="bank-box">
        <p class="bank-title">Pay by bank transfer</p>
        <dl>
            <div><dt>Bank</dt><dd><?= e($__bank['name']) ?></dd></div>
            <div><dt>Account name</dt><dd><?= e($__bank['holder']) ?></dd></div>
            <div><dt>Account number</dt><dd><strong data-copy-text><?= e($__bank['number']) ?></strong> <button type="button" class="copy-btn" data-copy hidden>Copy</button></dd></div>
            <div><dt>Amount</dt><dd><strong><?= e(money($order['total'])) ?></strong></dd></div>
            <div><dt>Reference</dt><dd><strong data-copy-text><?= e($order['order_no']) ?></strong> <button type="button" class="copy-btn" data-copy hidden>Copy</button></dd></div>
        </dl>
        <?php if ($__bank['note'] !== ''): ?><p><?= e($__bank['note']) ?></p><?php endif; ?>
        <p class="muted">Use the order number as the reference. We ship after payment is confirmed.</p>
    </div>
<?php else: ?>
    <p class="muted"><?= e($payment->instructions()) ?></p>
<?php endif; ?>
