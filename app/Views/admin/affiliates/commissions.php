<?php $qs = fn(array $o) => '?' . http_build_query(array_filter(['status' => $status, ...$o], fn($v) => $v !== '' && $v !== 1)); ?>
<h1>Commissions <small class="muted">(<?= (int) $total ?>)</small></h1>
<form method="get" class="filters">
    <select name="status">
        <option value="">Any status</option>
        <?php foreach ($statuses as $s): ?><option <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
    </select>
    <button class="btn">Filter</button>
</form>
<?php if ($rows === []): ?>
    <p class="muted">No commissions found.</p>
<?php else: ?>
<table>
    <thead><tr><th>Order</th><th>Affiliate</th><th>Created</th><th class="num">Margin</th><th class="num">Commission</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $m): ?>
        <tr>
            <td><a href="<?= e(url('/admin/orders/' . (int) $m['order_id'])) ?>"><?= e($m['order_no']) ?></a></td>
            <td><a href="<?= e(url('/admin/affiliates/' . (int) $m['affiliate_id'])) ?>"><?= e($m['aff_name']) ?></a></td>
            <td><?= e($m['created_at']) ?></td>
            <td class="num"><?= e(money($m['base_amount'])) ?></td>
            <td class="num"><?= e(money($m['amount'])) ?></td>
            <td><span class="badge cm-<?= e($m['status']) ?>"><?= e($m['status']) ?></span></td>
            <td><?php $m_back = '/admin/commissions'; require BASE_PATH . '/app/Views/admin/affiliates/_decide.php'; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if ($pages > 1): ?>
    <p class="pager">
        <?php if ($page > 1): ?><a href="<?= e($qs(['page' => $page - 1])) ?>">← Prev</a><?php endif; ?>
        Page <?= (int) $page ?> of <?= (int) $pages ?>
        <?php if ($page < $pages): ?><a href="<?= e($qs(['page' => $page + 1])) ?>">Next →</a><?php endif; ?>
    </p>
<?php endif; endif; ?>
