<?php /* expects $m (commission row) and $m_back ('affiliate' or a path) */ ?>
<?php if ($m['status'] === 'pending'): ?>
    <form method="post" action="<?= e(url('/admin/commissions/' . (int) $m['id'] . '/approve')) ?>" class="inline" data-swal-confirm="Approve this commission?" data-swal-text="Approved commission can be withdrawn by the affiliate." data-swal-button="Yes, approve"><?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($m_back === 'affiliate' ? 'affiliate' : '') ?>"><input type="hidden" name="affiliate_id" value="<?= (int) $m['affiliate_id'] ?>">
        <button class="btn">Approve</button></form>
<?php endif; ?>
<?php if (in_array($m['status'], ['pending', 'approved'], true)): ?>
    <form method="post" action="<?= e(url('/admin/commissions/' . (int) $m['id'] . '/reject')) ?>" class="inline" data-swal-confirm="Reject this commission?" data-swal-text="Rejected commissions are never paid out." data-swal-danger="1" data-swal-button="Yes, reject"><?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($m_back === 'affiliate' ? 'affiliate' : '') ?>"><input type="hidden" name="affiliate_id" value="<?= (int) $m['affiliate_id'] ?>">
        <button class="btn secondary">Reject</button></form>
<?php endif; ?>
