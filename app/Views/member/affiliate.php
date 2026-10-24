<h1>Affiliate</h1>
<p>Share your link. When someone buys within <?= (int) $days ?> days of clicking it, you earn commission on the completed order.</p>
<p><input readonly value="<?= e($link) ?>" onfocus="this.select()" style="width:100%;max-width:520px"></p>
<p class="muted">Your code: <strong><?= e($code) ?></strong> · Add <code>?ref=<?= e($code) ?></code> to any shop page URL, or <code>/r/<?= e($code) ?>?to=/products/your-product</code>. Orders you place yourself don't earn commission.</p>

<div class="stats">
    <div class="stat"><strong><?= (int) $stats['clicks'] ?></strong><span>Clicks</span></div>
    <div class="stat"><strong><?= (int) $stats['orders'] ?></strong><span>Referred orders</span></div>
    <div class="stat"><strong><?= e(money($stats['pending'])) ?></strong><span>Pending</span></div>
    <div class="stat"><strong><?= e(money($stats['approved'])) ?></strong><span>Approved (awaiting payout)</span></div>
    <div class="stat"><strong><?= e(money($stats['paid'])) ?></strong><span>Paid</span></div>
</div>

<h2>Commissions</h2>
<?php if ($commissions === []): ?>
    <p class="muted">None yet. Commission appears when a referred order is completed.</p>
<?php else: ?>
<table>
    <thead><tr><th>Date</th><th>Status</th><th class="num">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($commissions as $c): ?>
        <tr><td><?= e($c['created_at']) ?></td><td><span class="badge cm-<?= e($c['status']) ?>"><?= e($c['status']) ?></span></td><td class="num"><?= e(money($c['amount'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php if ($payouts !== []): ?>
<h2>Payouts</h2>
<table>
    <thead><tr><th>Date</th><th>Reference</th><th class="num">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($payouts as $p): ?>
        <tr><td><?= e($p['paid_at']) ?></td><td><?= e($p['reference']) ?></td><td class="num"><?= e(money($p['amount'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
