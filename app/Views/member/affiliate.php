<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Affiliate</h1>
            <p>Share your link. When someone buys within <?= (int) $days ?> days of clicking it, you earn commission on the completed order.</p>
        </div>
    </header>

    <section class="msg-card">
        <h2>Your link</h2>
        <p><input readonly value="<?= e($link) ?>" onfocus="this.select()" style="width:100%;max-width:520px"></p>
        <p class="muted">Your code: <strong><?= e($code) ?></strong> · Add <code>?ref=<?= e($code) ?></code> to any shop page URL, or <code>/r/<?= e($code) ?>?to=/products/your-product</code>. Orders you place yourself don't earn commission.</p>
    </section>

    <section class="msg-card">
        <div class="msg-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
            <div><strong><?= (int) $stats['clicks'] ?></strong><br><span class="muted">Clicks</span></div>
            <div><strong><?= (int) $stats['orders'] ?></strong><br><span class="muted">Referred orders</span></div>
            <div><strong><?= e(money($stats['pending'])) ?></strong><br><span class="muted">Pending</span></div>
            <div><strong><?= e(money($stats['approved'])) ?></strong><br><span class="muted">Approved, still owed</span></div>
            <div><strong><?= e(money($stats['paid'])) ?></strong><br><span class="muted">Paid out</span></div>
        </div>
    </section>

    <section class="msg-card">
        <h2>Withdraw</h2>
        <p>You can withdraw <strong><?= e(money($balance['available'] / 100)) ?></strong> now. Enter any amount up to that. HQ transfers it to your bank account, and the RM<?= e(\App\Services\AffiliateWithdrawalService::FEE) ?> transaction fee is taken from it, so you receive the amount less RM<?= e(\App\Services\AffiliateWithdrawalService::FEE) ?>.</p>
        <?php if ($balance['held'] > 0): ?>
            <p class="muted"><?= e(money($balance['held'] / 100)) ?> is held for a withdrawal waiting for HQ.</p>
        <?php endif; ?>
        <?php if ($balance['available'] > 100): ?>
        <form method="post" action="<?= e(url('/member/affiliate/withdraw')) ?>" novalidate class="card-form" data-busy-form>
            <?= csrf_field() ?>
            <label>Amount to withdraw (RM)
                <input name="amount" value="<?= old('amount') ?>" inputmode="decimal" required placeholder="200.00">
                <?= field_error('amount') ?>
            </label>
            <label>Bank
                <select name="bank_name" required>
                    <option value="">Choose a bank</option>
                    <?php foreach ($banks as $group => $names): ?>
                        <optgroup label="<?= e($group) ?>">
                            <?php foreach ($names as $name): ?>
                                <option value="<?= e($name) ?>" <?= old('bank_name') === $name ? 'selected' : '' ?>><?= e($name) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <?= field_error('bank_name') ?>
            </label>
            <label>Account holder name
                <input name="account_holder" value="<?= old('account_holder') ?>" maxlength="190" required autocomplete="off">
                <?= field_error('account_holder') ?>
            </label>
            <label>Account number
                <input name="account_no" value="" maxlength="40" inputmode="numeric" required autocomplete="off">
                <small class="muted">Type it carefully. HQ pays to exactly this number.</small>
                <?= field_error('account_no') ?>
            </label>
            <div class="msg-row"><span></span><button class="btn">Request withdrawal</button></div>
        </form>
        <?php else: ?>
            <p class="msg-empty">Your approved commission will show here once it is ready to withdraw.</p>
        <?php endif; ?>

        <?php if ($withdrawals !== []): ?>
        <h3 style="margin-top:1.2rem">Your withdrawals</h3>
        <div class="table-wrap">
        <table class="msg-table">
            <thead><tr><th>Requested</th><th class="num">Amount</th><th class="num">You receive</th><th>Bank</th><th>Status</th><th>Reference</th></tr></thead>
            <tbody>
            <?php foreach ($withdrawals as $w): ?>
                <tr>
                    <td><small><?= e($w['requested_at']) ?></small></td>
                    <td class="num"><?= e(money($w['amount'])) ?></td>
                    <td class="num"><?= e(money($w['net'])) ?></td>
                    <td><?= e($w['bank_name']) ?></td>
                    <td><span class="msg-pill <?= $w['status'] === 'paid' ? 'ok' : ($w['status'] === 'rejected' ? 'bad' : 'warn') ?>"><?= e($w['status']) ?></span><?= $w['status'] === 'rejected' && $w['note'] ? '<br><small class="muted">' . e($w['note']) . '</small>' : '' ?></td>
                    <td><?= e((string) $w['reference']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>

    <section class="msg-card">
        <h2>Commissions</h2>
        <?php if ($commissions === []): ?>
            <p class="msg-empty">None yet. Commission appears when a referred order is completed.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table class="msg-table">
            <thead><tr><th>Date</th><th>Status</th><th class="num">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($commissions as $c): ?>
                <tr>
                    <td><small><?= e($c['created_at']) ?></small></td>
                    <td><span class="msg-pill <?= $c['status'] === 'approved' || $c['status'] === 'paid' ? 'ok' : ($c['status'] === 'pending' ? 'warn' : '') ?>"><?= e($c['status']) ?></span></td>
                    <td class="num"><?= e(money($c['amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>

    <?php if ($payouts !== []): ?>
    <section class="msg-card">
        <h2>Payouts</h2>
        <div class="table-wrap">
        <table class="msg-table">
            <thead><tr><th>Date</th><th>Reference</th><th class="num">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($payouts as $p): ?>
                <tr><td><small><?= e($p['paid_at']) ?></small></td><td><?= e($p['reference']) ?></td><td class="num"><?= e(money($p['amount'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endif; ?>
</div>
