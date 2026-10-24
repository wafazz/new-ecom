<?php
/** Expects $status, $rows. Pending requests carry the actions; the account number is shown in full because HQ transfers to it. */
$tabs = ['pending' => 'Pending', 'paid' => 'Paid', 'rejected' => 'Rejected'];
$pendingTotal = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'pending') {
        $pendingTotal += (int) round((float) $r['net'] * 100);
    }
}
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Withdrawals</h1>
            <p class="muted">Affiliates ask for their commission here. Transfer the amount to the bank account shown, then mark it paid with the bank reference. A rejected request goes back into the member's balance.</p>
        </div>
    </header>

    <nav class="msg-tabs" aria-label="Withdrawal status">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="<?= e(url('/admin/withdrawals?status=' . $key)) ?>" class="<?= $status === $key ? 'is-on' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($status === 'pending'): ?>
        <p class="muted" style="margin:.6rem 0">Waiting for transfer: <strong><?= e(money($pendingTotal / 100)) ?></strong> to send to <?= count(array_filter($rows, fn($r) => $r['status'] === 'pending')) ?> member(s).</p>
    <?php endif; ?>

    <section class="msg-card">
        <?php if ($rows === []): ?>
            <p class="msg-empty">No <?= e(strtolower($tabs[$status])) ?> withdrawals.</p>
        <?php else: ?>
            <div class="table-wrap">
            <table class="msg-table">
                <thead><tr><th>Requested</th><th>Member</th><th class="num">Amount</th><th class="num">Fee</th><th class="num">Transfer</th><th>Bank account</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><small><?= e((string) $r['requested_at']) ?></small></td>
                        <td><?= e((string) $r['member_name']) ?><br><small class="muted"><?= e((string) $r['member_email']) ?></small></td>
                        <td class="num"><?= e(money($r['amount'])) ?></td>
                        <td class="num"><?= e(money($r['fee'])) ?></td>
                        <td class="num"><strong><?= e(money($r['net'])) ?></strong></td>
                        <td>
                            <?= e((string) $r['bank_name']) ?><br>
                            <?= e((string) $r['account_holder']) ?><br>
                            <code><?= e((string) $r['account_no']) ?></code>
                        </td>
                        <td>
                            <span class="msg-pill <?= $r['status'] === 'paid' ? 'ok' : ($r['status'] === 'rejected' ? 'neu' : 'warn') ?>"><?= e($r['status']) ?></span>
                            <?php if ($r['reference']): ?><br><small>Ref: <?= e((string) $r['reference']) ?></small><?php endif; ?>
                            <?php if ($r['note']): ?><br><small class="muted"><?= e((string) $r['note']) ?></small><?php endif; ?>
                        </td>
                        <td class="num">
                            <?php if ($r['status'] === 'pending'): ?>
                                <form method="post" action="<?= e(url('/admin/withdrawals/' . (int) $r['id'] . '/paid')) ?>" class="inline" data-swal-confirm="Mark RM<?= e(number_format((float) $r['net'], 2)) ?> as paid to <?= e((string) $r['member_name']) ?>?" data-swal-text="Only do this after the bank transfer has gone through. Use the transfer reference." data-swal-button="Yes, it is paid">
                                    <?= csrf_field() ?>
                                    <input name="reference" placeholder="Bank reference" maxlength="190" required style="width:9rem">
                                    <button class="btn" type="submit">Mark paid</button>
                                </form>
                                <form method="post" action="<?= e(url('/admin/withdrawals/' . (int) $r['id'] . '/reject')) ?>" class="inline" data-swal-confirm="Reject this withdrawal?" data-swal-text="The amount goes back into the member's balance." data-swal-danger="1" data-swal-button="Yes, reject">
                                    <?= csrf_field() ?>
                                    <input name="note" placeholder="Reason (optional)" maxlength="255" style="width:9rem">
                                    <button class="btn secondary" type="submit">Reject</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>
</div>
