<h1>Affiliates <small class="muted">(<?= count($affiliates) ?>)</small></h1>
<?php if ($affiliates === []): ?>
    <p class="muted">No member has opened their affiliate page yet.</p>
<?php else: ?>
<table>
    <thead><tr><th>Affiliate</th><th>Code</th><th class="num">Clicks</th><th class="num">Orders</th><th class="num">Pending</th><th class="num">Approved</th><th class="num">Paid</th></tr></thead>
    <tbody>
    <?php foreach ($affiliates as $a): ?>
        <tr>
            <td><a href="<?= e(url('/admin/affiliates/' . (int) $a['id'])) ?>"><?= e($a['name']) ?></a><br><small class="muted"><?= e($a['email']) ?><?= $a['status'] === 'active' ? '' : ' · disabled' ?></small></td>
            <td><?= e($a['affiliate_code']) ?></td>
            <td class="num"><?= (int) $a['clicks'] ?></td>
            <td class="num"><?= (int) $a['orders'] ?></td>
            <td class="num"><?= e(money($a['pending'])) ?></td>
            <td class="num"><?= e(money($a['approved'])) ?></td>
            <td class="num"><?= e(money($a['paid'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
