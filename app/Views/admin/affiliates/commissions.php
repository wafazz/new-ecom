<?php
$qs = fn(array $o) => '?' . http_build_query(array_filter(['status' => $status, ...$o], fn($v) => $v !== '' && $v !== 1));
$tabs = array_merge([''], $statuses);
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Commissions</h1>
            <p class="muted">Commission on each referred order. Approve a pending one before it can be withdrawn; reject one that should not pay.</p>
        </div>
        <span class="msg-pill"><?= (int) $total ?> commission<?= (int) $total === 1 ? '' : 's' ?></span>
    </header>

    <nav class="msg-tabs" aria-label="Commission status">
        <?php foreach ($tabs as $s): ?>
            <a href="<?= e(url('/admin/commissions' . ($s === '' ? '' : '?status=' . $s))) ?>" class="<?= $status === $s ? 'is-on' : '' ?>"><?= e($s === '' ? 'All' : ucfirst($s)) ?></a>
        <?php endforeach; ?>
    </nav>

    <section class="msg-card">
        <?php if ($rows === []): ?>
            <p class="msg-empty">No commissions found.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table class="msg-table">
            <thead><tr><th>Order</th><th>Affiliate</th><th>Created</th><th class="num">Commission base</th><th class="num">Commission</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $m): ?>
                <tr>
                    <td><a href="<?= e(url('/admin/orders/' . (int) $m['order_id'])) ?>"><?= e($m['order_no']) ?></a></td>
                    <td><a href="<?= e(url('/admin/affiliates/' . (int) $m['affiliate_id'])) ?>"><?= e($m['aff_name']) ?></a></td>
                    <td><small><?= e($m['created_at']) ?></small></td>
                    <td class="num"><?= e(money($m['base_amount'])) ?></td>
                    <td class="num"><?= e(money($m['amount'])) ?></td>
                    <td><span class="msg-pill <?= $m['status'] === 'approved' || $m['status'] === 'paid' ? 'ok' : ($m['status'] === 'pending' ? 'warn' : '') ?>"><?= e($m['status']) ?></span></td>
                    <td class="num"><?php $m_back = '/admin/commissions'; require BASE_PATH . '/app/Views/admin/affiliates/_decide.php'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="msg-row" aria-label="Pages" style="margin-top:.8rem">
                <?php if ($page > 1): ?><a class="btn secondary" href="<?= e($qs(['page' => $page - 1])) ?>">‹ Previous</a><?php else: ?><span></span><?php endif; ?>
                <span class="muted">Page <?= (int) $page ?> of <?= (int) $pages ?></span>
                <?php if ($page < $pages): ?><a class="btn secondary" href="<?= e($qs(['page' => $page + 1])) ?>">Next ›</a><?php else: ?><span></span><?php endif; ?>
            </nav>
        <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
