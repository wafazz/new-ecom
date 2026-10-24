<?php
/** Order progress: Placed → Processing → Shipped → Completed. Expects $order (status, created_at). */
$steps = ['pending' => 'Placed', 'processing' => 'Processing', 'shipped' => 'Shipped', 'completed' => 'Delivered'];
$keys = array_keys($steps);
$cancelled = $order['status'] === 'cancelled';
$returned = $order['status'] === 'returned';
$at = array_search($order['status'], $keys, true);
$at = $at === false ? 0 : $at;
?>
<?php if ($returned): ?>
    <div class="tracker cancelled" role="status"><span class="t-icon"><?= icon('x') ?></span><div><strong>This order was returned to the seller</strong><span class="muted">Stock was put back. Our team will contact you about any refund.</span></div></div>
<?php elseif ($cancelled): ?>
    <div class="tracker cancelled" role="status"><span class="t-icon"><?= icon('x') ?></span><div><strong>This order was cancelled</strong><span class="muted">Stock was released and no further action is needed.</span></div></div>
<?php else: ?>
    <ol class="tracker" aria-label="Order progress">
        <?php foreach ($steps as $k => $label): $i = array_search($k, $keys, true); ?>
            <li class="<?= $i < $at ? 'done' : ($i === $at ? 'now' : '') ?>"<?= $i === $at ? ' aria-current="step"' : '' ?>><span class="t-dot"><?= $i < $at || $order['status'] === 'completed' ? icon('check', 'i i-sm') : $i + 1 ?></span><span class="t-label"><?= e($label) ?></span></li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>
