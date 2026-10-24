<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Affiliates</h1>
            <p class="muted">Members who share a referral link. Open one to see its clicks, orders and commission.</p>
        </div>
        <span class="msg-pill ok"><?= count($affiliates) ?> affiliate<?= count($affiliates) === 1 ? '' : 's' ?></span>
    </header>

    <section class="msg-card">
        <?php if ($affiliates === []): ?>
            <p class="msg-empty">No member has opened their affiliate page yet.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table class="msg-table">
            <thead><tr><th>Affiliate</th><th>Code</th><th class="num">Clicks</th><th class="num">Orders</th><th class="num">Pending</th><th class="num">Approved</th><th class="num">Paid</th></tr></thead>
            <tbody>
            <?php foreach ($affiliates as $a): ?>
                <tr>
                    <td><a href="<?= e(url('/admin/affiliates/' . (int) $a['id'])) ?>"><?= e($a['name']) ?></a><br><small class="muted"><?= e($a['email']) ?><?= $a['status'] === 'active' ? '' : ' · <span class="msg-status bad">disabled</span>' ?></small></td>
                    <td><code><?= e($a['affiliate_code']) ?></code></td>
                    <td class="num"><?= (int) $a['clicks'] ?></td>
                    <td class="num"><?= (int) $a['orders'] ?></td>
                    <td class="num"><?= e(money($a['pending'])) ?></td>
                    <td class="num"><?= e(money($a['approved'])) ?></td>
                    <td class="num"><?= e(money($a['paid'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
</div>
