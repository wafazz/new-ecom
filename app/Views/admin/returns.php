<?php
/** Expects $status (filter), $rows (return requests with order details). */
$tabs = ['' => 'All', 'requested' => 'Waiting', 'approved' => 'Approved', 'received' => 'Received', 'refunded' => 'Refunded', 'rejected' => 'Rejected'];
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Returns and refunds</h1>
            <p class="muted">Approve, then mark the items received (stock goes back once), then record the refund amount. The shop does not send the money from here.</p>
        </div>
    </header>

    <nav class="msg-card" aria-label="Filter returns">
        <?php foreach ($tabs as $k => $label): ?>
            <a class="btn <?= $status === $k ? '' : 'secondary' ?> sm" href="<?= e(url('/admin/returns' . ($k !== '' ? '?status=' . $k : ''))) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <section class="msg-card">
        <?php if ($rows === []): ?>
            <p class="muted">No return requests here.</p>
        <?php else: ?>
            <div class="msg-table-wrap">
                <table class="msg-table">
                    <thead><tr><th>Order</th><th>Customer</th><th>Reason</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?= e($r['order_no']) ?><br><small class="muted">Total RM<?= e(number_format((float) $r['total'], 2)) ?></small></td>
                                <td><?= e($r['customer_name']) ?><br><small class="muted"><?= e($r['customer_email']) ?></small></td>
                                <td><?= e($r['reason']) ?><?php if (!empty($r['staff_note'])): ?><br><small class="muted">Note: <?= e($r['staff_note']) ?></small><?php endif; ?></td>
                                <td><?= e($r['status']) ?><?php if ($r['refund_amount'] !== null): ?><br><small>RM<?= e(number_format((float) $r['refund_amount'], 2)) ?></small><?php endif; ?></td>
                                <td>
                                    <?php $act = fn(string $a) => e(url('/admin/returns/' . (int) $r['id'] . '/' . $a)); ?>
                                    <?php if ($r['status'] === 'requested'): ?>
                                        <form method="post" action="<?= $act('approve') ?>"><?= csrf_field() ?><button class="btn sm">Approve</button></form>
                                        <form method="post" action="<?= $act('reject') ?>"><?= csrf_field() ?><input name="note" placeholder="Reason" required maxlength="500"><button class="btn secondary sm">Reject</button></form>
                                    <?php elseif ($r['status'] === 'approved'): ?>
                                        <form method="post" action="<?= $act('receive') ?>"><?= csrf_field() ?><button class="btn sm">Mark received</button></form>
                                    <?php elseif ($r['status'] === 'received'): ?>
                                        <form method="post" action="<?= $act('refund') ?>" data-swal-confirm="Record this refund?" data-swal-text="This records the amount only. Pay it through your bank or gateway." data-swal-button="Record refund"><?= csrf_field() ?>
                                            <input name="amount" inputmode="decimal" required placeholder="RM amount" value="<?= e(number_format((float) $r['total'], 2, '.', '')) ?>">
                                            <button class="btn sm">Record refund</button></form>
                                    <?php else: ?><span class="muted">Done</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
