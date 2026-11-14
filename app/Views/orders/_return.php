<?php
/** Expects $order and $returnAction (path|null). Shows the return status, or the request form when one is allowed. */
use App\Services\Modules;
use App\Services\ReturnService;

if (!Modules::enabled('returns') || empty($returnAction)) {
    return;
}
$rr = ReturnService::forOrder((int) $order['id']);
$can = ReturnService::eligible($order);
$labels = [
    'requested' => 'Return requested. The shop will reply soon.',
    'approved' => 'Return approved. Please send the items back, then the shop records the refund.',
    'rejected' => 'Return rejected.',
    'received' => 'Items received back. The refund is being processed.',
    'refunded' => 'Refunded: RM' . number_format((float) ($rr['refund_amount'] ?? 0), 2) . '.',
];
?>
<?php if ($rr !== null): ?>
    <section class="return-status">
        <p><strong>Return:</strong> <?= e($labels[$rr['status']] ?? $rr['status']) ?></p>
        <?php if (!empty($rr['staff_note'])): ?><p class="muted">Shop note: <?= e($rr['staff_note']) ?></p><?php endif; ?>
    </section>
<?php endif; ?>
<?php if ($can['ok']): ?>
    <form method="post" action="<?= e(url($returnAction)) ?>" class="return-form card-form" data-busy-form>
        <?= csrf_field() ?>
        <label>Why do you want to return this order?
            <textarea name="reason" rows="3" required minlength="10" maxlength="500" placeholder="For example: the item arrived damaged."></textarea></label>
        <button class="btn secondary" type="submit">Ask for a return</button>
    </form>
<?php endif; ?>
