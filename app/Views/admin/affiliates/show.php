<div class="msg-page">
    <header class="msg-head">
        <div>
            <p class="muted" style="margin:0"><a href="<?= e(url('/admin/affiliates')) ?>">← Affiliates</a></p>
            <h1><?= e($aff['name']) ?></h1>
            <p class="muted"><?= e($aff['email']) ?> · code <code><?= e($aff['affiliate_code']) ?></code></p>
        </div>
    </header>

    <section class="msg-card">
        <div class="msg-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
            <div><strong><?= (int) $stats['clicks'] ?></strong><br><span class="muted">Clicks</span></div>
            <div><strong><?= (int) $stats['orders'] ?></strong><br><span class="muted">Referred orders</span></div>
            <div><strong><?= e(money($stats['pending'])) ?></strong><br><span class="muted">Pending</span></div>
            <div><strong><?= e(money($stats['approved'])) ?></strong><br><span class="muted">Still owed</span></div>
            <div><strong><?= e(money($stats['paid'])) ?></strong><br><span class="muted">Paid out</span></div>
        </div>
    </section>

    <section class="msg-card">
        <h2>Payouts</h2>
        <p class="muted">Payouts are made from the <a href="<?= e(url('/admin/withdrawals')) ?>">Withdrawals</a> page. The member asks for an amount there, and HQ pays it.</p>
    </section>

    <section class="msg-card">
        <h2>Commissions</h2>
        <?php if ($commissions === []): ?>
            <p class="msg-empty">None yet.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table class="msg-table">
            <thead><tr><th>Order</th><th>Created</th><th class="num">Commission base</th><th class="num">Commission</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($commissions as $m): ?>
                <tr>
                    <td><a href="<?= e(url('/admin/orders/' . (int) $m['order_id'])) ?>"><?= e($m['order_no']) ?></a></td>
                    <td><small><?= e($m['created_at']) ?></small></td>
                    <td class="num"><?= e(money($m['base_amount'])) ?></td>
                    <td class="num"><?= e(money($m['amount'])) ?></td>
                    <td><span class="msg-pill <?= $m['status'] === 'approved' || $m['status'] === 'paid' ? 'ok' : ($m['status'] === 'pending' ? 'warn' : '') ?>"><?= e($m['status']) ?></span></td>
                    <td class="num"><?php $m_back = 'affiliate'; require BASE_PATH . '/app/Views/admin/affiliates/_decide.php'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>

    <?php if ($payouts !== []): ?>
    <section class="msg-card">
        <h2>Payout history</h2>
        <div class="table-wrap">
        <table class="msg-table">
            <thead><tr><th>Date</th><th>Reference</th><th>By</th><th class="num">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($payouts as $p): ?>
                <tr><td><small><?= e($p['paid_at']) ?></small></td><td><?= e($p['reference']) ?><?= $p['note'] ? '<br><small class="muted">' . e($p['note']) . '</small>' : '' ?></td><td><?= e($p['by_name'] ?? '') ?></td><td class="num"><?= e(money($p['amount'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endif; ?>
</div>
