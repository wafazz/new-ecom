<?php
/** Bank transfer receipts for the back office. Expects $order, $proofs (all, newest first), $base, $canUpdate. */
if ($order['payment_method'] !== 'bank_transfer') {
    return;
}
$__label = ['submitted' => ['Waiting for review', 'st-pending'], 'approved' => ['Approved', 'active'], 'rejected' => ['Rejected', 'st-cancelled'], 'superseded' => ['Replaced by a newer receipt', 'draft']];
$__open = $order['payment_status'] === 'unpaid' && $order['status'] !== 'cancelled';
$__bank = \App\Services\PaymentSettings::bank();
?>
<section class="panel proof-card" id="receipt">
    <h2>Payment receipt</h2>
    <?php if ($proofs === []): ?>
        <p class="muted"><?= $__open ? 'The customer has not sent a receipt yet. You can still mark the order paid below once the money is in your account.' : 'No receipt was sent for this order.' ?></p>
    <?php else: ?>
        <ul class="proof-list">
            <?php foreach ($proofs as $__p): [$__txt, $__cls] = $__label[$__p['status']] ?? ['', 'draft']; $__img = str_starts_with((string) $__p['mime'], 'image/'); ?>
                <li class="proof-item<?= $__p['status'] === 'superseded' ? ' old' : '' ?>">
                    <div class="row-between">
                        <div>
                            <strong><?= e($__p['bank_name']) ?></strong>
                            <p class="muted">Paid <strong><?= e(date('d M Y, H:i', strtotime((string) $__p['paid_at']))) ?></strong> · sent <?= e(date('d M Y, H:i', strtotime((string) $__p['created_at']))) ?> · amount to match: <strong><?= e(money($order['total'])) ?></strong></p>
                        </div>
                        <span class="badge <?= e($__cls) ?>"><?= e($__txt) ?></span>
                    </div>
                    <p><a href="<?= e(url($base . '/orders/' . (int) $order['id'] . '/proofs/' . (int) $__p['id'])) ?>" target="_blank" rel="noopener"><?= $__img ? 'View receipt' : 'Open PDF' ?>: <?= e($__p['original_name']) ?></a>
                        <span class="muted">(<?= e(number_format((int) $__p['size'] / 1024, 0)) ?> KB)</span></p>
                    <?php if ($__p['status'] === 'rejected'): ?><p class="status-bad"><strong>Reason sent to customer:</strong> <?= e((string) $__p['note']) ?><?= $__p['reviewer'] ? ' <span class="muted">(' . e($__p['reviewer']) . ')</span>' : '' ?></p><?php endif; ?>
                    <?php if ($__p['status'] === 'approved' && $__p['reviewer']): ?><p class="muted">Approved by <?= e($__p['reviewer']) ?></p><?php endif; ?>
                    <?php if ($__p['status'] === 'submitted' && $canUpdate && $__open): ?>
                        <div class="proof-actions">
                            <form method="post" action="<?= e(url($base . '/orders/' . (int) $order['id'] . '/proofs/' . (int) $__p['id'] . '/approve')) ?>" class="inline"
                                  data-swal-confirm="Approve this receipt and mark the order as paid?" data-swal-text="Check that <?= e(money($order['total'])) ?> reached <?= e($__bank['name'] ?: 'your bank') ?> first." data-swal-button="Yes, mark as paid">
                                <?= csrf_field() ?><button class="btn">Approve and mark paid</button></form>
                            <details class="proof-reject">
                                <summary class="btn secondary">Reject…</summary>
                                <form method="post" action="<?= e(url($base . '/orders/' . (int) $order['id'] . '/proofs/' . (int) $__p['id'] . '/reject')) ?>">
                                    <?= csrf_field() ?>
                                    <label>Reason (shown to the customer)
                                        <input name="reason" maxlength="255" required placeholder="e.g. The amount is less than the order total"></label>
                                    <button class="btn">Send back to customer</button>
                                </form>
                            </details>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
