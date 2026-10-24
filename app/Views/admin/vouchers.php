<?php
/** Expects $vouchers, $types. Form errors come back as field errors keyed by field name, and reopen the new-code popup. */
$now = time();
$status = function (array $v) use ($now): array {
    if ((int) $v['active'] !== 1) return ['Off', 'neu'];
    if ($v['expires_at'] !== null && strtotime((string) $v['expires_at']) <= $now) return ['Expired', 'bad'];
    if ($v['starts_at'] !== null && strtotime((string) $v['starts_at']) > $now) return ['Scheduled', 'info'];
    if ($v['max_uses'] !== null && (int) $v['used_count'] >= (int) $v['max_uses']) return ['Used up', 'warn'];
    return ['Active', 'ok'];
};
$worth = fn(array $v) => $v['type'] === 'percent'
    ? rtrim(rtrim(number_format((float) $v['value'], 2), '0'), '.') . '% off items'
    : money((float) $v['value']) . ' off items';
$reopen = !empty($_SESSION['_errors']);
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Vouchers</h1>
            <p class="muted">Discount codes for checkout. A percent code takes that share of the items, and a fixed code takes an amount off. Shipping is never discounted.</p>
        </div>
        <div class="row-between">
            <span class="msg-pill ok"><?= count($vouchers) ?> code<?= count($vouchers) === 1 ? '' : 's' ?></span>
            <button type="button" class="btn" data-voucher-open>New code</button>
        </div>
    </header>

    <dialog class="modal" id="voucher-modal" aria-labelledby="voucher-modal-title" data-reopen="<?= $reopen ? '1' : '0' ?>">
        <div class="modal-inner">
            <header class="modal-head">
                <h2 id="voucher-modal-title">New code</h2>
                <button type="button" class="icon-btn" data-voucher-close aria-label="Close">
                    <svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </header>
            <div class="modal-body">
                <form method="post" action="<?= e(url('/admin/vouchers')) ?>" novalidate class="grid-form">
                    <?= csrf_field() ?>
                    <label>Code
                        <input name="code" value="<?= old('code') ?>" maxlength="40" required style="text-transform:uppercase" placeholder="RAYA10">
                        <?= field_error('code') ?>
                    </label>
                    <label>Type
                        <select name="type" required>
                            <?php foreach ($types as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= old('type') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= field_error('type') ?>
                    </label>
                    <label>Amount (% or RM)
                        <input name="value" value="<?= old('value') ?>" inputmode="decimal" required placeholder="10">
                        <?= field_error('value') ?>
                    </label>
                    <label>Minimum spend (RM)
                        <input name="min_subtotal" value="<?= old('min_subtotal', '0') ?>" inputmode="decimal">
                        <?= field_error('min_subtotal') ?>
                    </label>
                    <label>Total uses (empty = no limit)
                        <input name="max_uses" value="<?= old('max_uses') ?>" inputmode="numeric">
                        <?= field_error('max_uses') ?>
                    </label>
                    <label>Starts (optional)
                        <input type="datetime-local" name="starts_at" value="<?= old('starts_at') ?>">
                        <?= field_error('starts_at') ?>
                    </label>
                    <label>Ends (optional)
                        <input type="datetime-local" name="expires_at" value="<?= old('expires_at') ?>">
                        <?= field_error('expires_at') ?>
                    </label>
                    <div class="row-between">
                        <button type="button" class="btn secondary" data-voucher-close>Cancel</button>
                        <button class="btn">Create code</button>
                    </div>
                </form>
            </div>
        </div>
    </dialog>

    <section class="msg-card">
        <h2>All codes</h2>
        <?php if ($vouchers === []): ?>
            <p class="msg-empty" style="margin-top:.6rem">No codes yet. Use <strong>New code</strong> to make one.</p>
        <?php else: ?>
            <div class="table-wrap" style="margin-top:.6rem">
            <table class="msg-table">
                <thead><tr><th>Code</th><th>Gives</th><th>Minimum</th><th>Used</th><th>Valid</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($vouchers as $v): [$label, $tone] = $status($v); ?>
                    <tr>
                        <td><code><?= e($v['code']) ?></code></td>
                        <td><?= e($worth($v)) ?></td>
                        <td><?= (float) $v['min_subtotal'] > 0 ? e(money((float) $v['min_subtotal'])) : '<span class="muted">none</span>' ?></td>
                        <td><?= (int) $v['used_count'] ?><?= $v['max_uses'] !== null ? ' / ' . (int) $v['max_uses'] : '' ?></td>
                        <td><small><?= $v['starts_at'] ? e(date('d M Y H:i', strtotime((string) $v['starts_at']))) : 'now' ?> → <?= $v['expires_at'] ? e(date('d M Y H:i', strtotime((string) $v['expires_at']))) : 'no end' ?></small></td>
                        <td><span class="msg-pill <?= e($tone) ?>"><?= e($label) ?></span></td>
                        <td class="num">
                            <form method="post" action="<?= e(url('/admin/vouchers/' . (int) $v['id'] . '/active')) ?>" class="inline">
                                <?= csrf_field() ?><button class="btn secondary" type="submit"><?= (int) $v['active'] === 1 ? 'Switch off' : 'Switch on' ?></button>
                            </form>
                            <?php if ($tone === 'ok'): ?>
                                <?php if ($canEmail): ?>
                                    <form method="post" action="<?= e(url('/admin/vouchers/' . (int) $v['id'] . '/email')) ?>" class="inline" data-swal-confirm="Email <?= e($v['code']) ?> to <?= (int) $memberCount ?> member(s)?" data-swal-text="Each member who has not unsubscribed gets this code by email. Emails cannot be unsent." data-swal-button="Send">
                                        <?= csrf_field() ?><button class="btn" type="submit">Email members</button>
                                    </form>
                                <?php else: ?>
                                    <button class="btn secondary" type="button" disabled title="Turn email on and save the sender first">Email members</button>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ((int) $v['used_count'] === 0): ?>
                                <form method="post" action="<?= e(url('/admin/vouchers/' . (int) $v['id'] . '/delete')) ?>" class="inline" data-swal-confirm="Delete this code?" data-swal-text="This cannot be undone." data-swal-danger="1" data-swal-button="Yes, delete">
                                    <?= csrf_field() ?><button class="btn secondary" type="submit">Delete</button>
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
