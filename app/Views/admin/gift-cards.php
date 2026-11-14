<?php
/** Expects $cards and $issued (the code of a card just created, shown once). */
$money = fn(int $c): string => 'RM ' . number_format($c / 100, 2);
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Gift cards</h1>
            <p class="muted">Issue a card for an amount. Customers enter the code at checkout. A cancelled order gives its card amount back.</p>
        </div>
    </header>

    <?php if ($issued): ?>
        <section class="msg-card">
            <h2>New card code</h2>
            <p><strong class="gift-code"><?= e((string) $issued) ?></strong></p>
            <p class="muted">Copy it now. For safety it is not shown again.</p>
        </section>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/admin/gift-cards')) ?>" class="msg-card card-form">
        <?= csrf_field() ?>
        <label>Amount (RM)
            <input name="amount" inputmode="decimal" placeholder="for example 50" required></label>
        <div class="msg-row"><span></span><button class="btn">Create gift card</button></div>
    </form>

    <section class="msg-card">
        <h2>Cards</h2>
        <?php if ($cards === []): ?>
            <p class="muted">No gift cards yet.</p>
        <?php else: ?>
            <div class="msg-table-wrap">
                <table class="msg-table">
                    <thead><tr><th>Code</th><th>Issued</th><th>Balance</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($cards as $c): ?>
                            <tr>
                                <td><code><?= e(substr((string) $c['code'], 0, 4)) ?>-····-····</code></td>
                                <td><?= e($money((int) $c['initial_cents'])) ?></td>
                                <td><?= e($money((int) $c['balance_cents'])) ?></td>
                                <td><?= (int) $c['active'] === 1 ? 'On' : 'Off' ?></td>
                                <td>
                                    <form method="post" action="<?= e(url('/admin/gift-cards/' . (int) $c['id'] . '/active')) ?>">
                                        <?= csrf_field() ?><input type="hidden" name="active" value="<?= (int) $c['active'] === 1 ? '0' : '1' ?>">
                                        <button class="btn secondary sm"><?= (int) $c['active'] === 1 ? 'Switch off' : 'Switch on' ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
