<?php /** Expects: $payAction (path|null) */ ?>
<?php if (!empty($payAction)): ?>
    <form method="post" action="<?= e(url($payAction)) ?>" class="pay-now">
        <?= csrf_field() ?><button class="btn">Pay now with Billplz</button>
        <small class="muted">You'll be redirected to Billplz to complete the payment.</small>
    </form>
<?php endif; ?>
