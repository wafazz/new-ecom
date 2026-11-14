<?php
/** Expects $waiting (receipts to check) and $recent (latest invoices). */
$money = fn(float $v): string => 'RM ' . number_format($v, 2);
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Module invoices</h1>
            <p class="muted">Check each transfer receipt against the bank statement before you approve it. Approving turns the module on for its paid period.</p>
        </div>
    </header>

    <section class="msg-card">
        <h2>Waiting for a decision</h2>
        <?php if ($waiting === []): ?>
            <p class="muted">No receipts are waiting.</p>
        <?php else: ?>
            <div class="msg-table-wrap">
                <table class="msg-table">
                    <thead><tr><th>Invoice</th><th>Module</th><th>Amount</th><th>Period</th><th>From</th><th>Receipt</th><th>Decide</th></tr></thead>
                    <tbody>
                        <?php foreach ($waiting as $w): ?>
                            <tr>
                                <td>#<?= (int) $w['id'] ?></td>
                                <td><?= e(\App\Services\Modules::LIST[$w['module_key']][0] ?? $w['module_key']) ?></td>
                                <td><?= e($money((float) $w['amount'])) ?></td>
                                <td><?= e($w['period_start']) ?> to <?= e($w['period_end'] === '9999-12-31' ? 'no end' : $w['period_end']) ?></td>
                                <td><?= e((string) $w['requester']) ?></td>
                                <td><a href="<?= e(url('/superadmin/invoices/' . (int) $w['id'] . '/receipt')) ?>" target="_blank" rel="noopener">View</a></td>
                                <td>
                                    <form method="post" action="<?= e(url('/superadmin/invoices/' . (int) $w['id'] . '/approve')) ?>" data-swal-confirm="Approve this receipt?" data-swal-text="The module is turned on for this period." data-swal-button="Approve"><?= csrf_field() ?><button class="btn sm">Approve</button></form>
                                    <form method="post" action="<?= e(url('/superadmin/invoices/' . (int) $w['id'] . '/reject')) ?>" class="inline-form"><?= csrf_field() ?><input name="note" placeholder="Reason" maxlength="255" required><button class="btn secondary sm">Reject</button></form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="msg-card">
        <h2>Recent invoices</h2>
        <?php if ($recent === []): ?>
            <p class="muted">No invoices yet.</p>
        <?php else: ?>
            <div class="msg-table-wrap">
                <table class="msg-table">
                    <thead><tr><th>#</th><th>Module</th><th>Amount</th><th>Method</th><th>Status</th><th>Paid</th></tr></thead>
                    <tbody>
                        <?php foreach ($recent as $r): ?>
                            <tr>
                                <td><?= (int) $r['id'] ?></td>
                                <td><?= e(\App\Services\Modules::LIST[$r['module_key']][0] ?? $r['module_key']) ?></td>
                                <td><?= e($money((float) $r['amount'])) ?></td>
                                <td><?= e((string) $r['method']) ?></td>
                                <td><?= e($r['status']) ?></td>
                                <td><?= e((string) $r['paid_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
