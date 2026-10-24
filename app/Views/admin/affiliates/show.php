<p><a href="<?= e(url('/admin/affiliates')) ?>">← Affiliates</a></p>
<h1><?= e($aff['name']) ?> <small class="muted"><?= e($aff['email']) ?> · <?= e($aff['affiliate_code']) ?></small></h1>
<div class="stats">
    <div class="stat"><strong><?= (int) $stats['clicks'] ?></strong><span>Clicks</span></div>
    <div class="stat"><strong><?= (int) $stats['orders'] ?></strong><span>Referred orders</span></div>
    <div class="stat"><strong><?= e(money($stats['pending'])) ?></strong><span>Pending</span></div>
    <div class="stat"><strong><?= e(money($stats['approved'])) ?></strong><span>Approved</span></div>
    <div class="stat"><strong><?= e(money($stats['paid'])) ?></strong><span>Paid</span></div>
</div>

<section>
    <h2>Payouts</h2>
    <p class="muted">Payouts are made from the <a href="<?= e(url('/admin/withdrawals')) ?>">Withdrawals</a> page. The member asks for an amount there, and HQ pays it.</p>
</section>

<h2>Commissions</h2>
<?php if ($commissions === []): ?>
    <p class="muted">None yet.</p>
<?php else: ?>
<table>
    <thead><tr><th>Order</th><th>Created</th><th class="num">Commission base</th><th class="num">Commission</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($commissions as $m): ?>
        <tr>
            <td><a href="<?= e(url('/admin/orders/' . (int) $m['order_id'])) ?>"><?= e($m['order_no']) ?></a></td>
            <td><?= e($m['created_at']) ?></td>
            <td class="num"><?= e(money($m['base_amount'])) ?></td>
            <td class="num"><?= e(money($m['amount'])) ?></td>
            <td><span class="badge cm-<?= e($m['status']) ?>"><?= e($m['status']) ?></span></td>
            <td><?php $m_back = 'affiliate'; require BASE_PATH . '/app/Views/admin/affiliates/_decide.php'; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php if ($payouts !== []): ?>
<h2>Payouts</h2>
<table>
    <thead><tr><th>Date</th><th>Reference</th><th>By</th><th class="num">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($payouts as $p): ?>
        <tr><td><?= e($p['paid_at']) ?></td><td><?= e($p['reference']) ?><?= $p['note'] ? '<br><small class="muted">' . e($p['note']) . '</small>' : '' ?></td><td><?= e($p['by_name'] ?? '') ?></td><td class="num"><?= e(money($p['amount'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
