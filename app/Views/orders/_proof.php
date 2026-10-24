<?php
/**
 * Bank transfer receipts for an order, and the form to send one. Expects $order, $proofs (newest first),
 * $proofAction (POST path or null = read only) and $proofFileBase (path to view a receipt, or null = no links).
 */
use App\Services\ReceiptService;

if ($order['payment_method'] !== 'bank_transfer') {
    return;
}
$__canSend = !empty($proofAction) && ReceiptService::canUpload($order);
$__latest = $proofs[0] ?? null;
if ($proofs === [] && !$__canSend) {
    return;
}
$__label = ['submitted' => ['Waiting for us to check it', 'st-pending'], 'approved' => ['Payment confirmed', 'active'], 'rejected' => ['Not accepted', 'st-cancelled']];
?>
<section class="panel co-card proof-card" id="receipt">
    <h2>Payment receipt</h2>
    <?php if ($proofs !== []): ?>
        <ul class="proof-list">
            <?php foreach ($proofs as $__p): [$__txt, $__cls] = $__label[$__p['status']] ?? ['', 'draft']; ?>
                <li class="proof-item">
                    <div class="row-between">
                        <div><strong><?= e($__p['bank_name']) ?></strong>
                            <p class="muted">Paid <?= e(date('d M Y, H:i', strtotime((string) $__p['paid_at']))) ?> · sent <?= e(date('d M Y, H:i', strtotime((string) $__p['created_at']))) ?></p></div>
                        <span class="badge <?= e($__cls) ?>"><?= e($__txt) ?></span>
                    </div>
                    <?php if ($__p['status'] === 'rejected' && $__p['note']): ?><p class="status-bad"><strong>Why:</strong> <?= e($__p['note']) ?></p><?php endif; ?>
                    <?php if (!empty($proofFileBase)): ?><p><a href="<?= e(url($proofFileBase . '/' . (int) $__p['id'])) ?>" target="_blank" rel="noopener"><?= e($__p['original_name']) ?></a> <span class="muted">(<?= e(number_format((int) $__p['size'] / 1024, 0)) ?> KB)</span></p><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($__canSend): ?>
        <h3><?= $__latest && $__latest['status'] === 'rejected' ? 'Please send a new receipt' : ($proofs === [] ? 'Already paid? Send us your receipt' : 'Send a different receipt') ?></h3>
        <form method="post" action="<?= e(url($proofAction)) ?>" enctype="multipart/form-data" novalidate data-proof-form>
            <?= csrf_field() ?>
            <?php require BASE_PATH . '/app/Views/partials/proof_fields.php'; ?>
            <p><button class="s-btn btn" type="submit">Send receipt</button></p>
        </form>
    <?php endif; ?>
</section>
