<?php
/** Expects $rows (paid modules: label, quotes per plan, until, subscribed, covered, open invoice), $bank, $billplzReady. */
$money = fn(float $v): string => 'RM ' . number_format($v, 2);
$periods = \App\Services\ModulePricing::PERIODS;
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Module billing</h1>
            <p class="muted">Pick the plan you want for each module. A paid module stays on while it is paid up. Pay by Billplz, or transfer to the bank account below and upload the receipt. The superadmin checks each receipt.</p>
        </div>
    </header>

    <?php if ($rows === []): ?>
        <section class="msg-card"><p class="muted">No module has a plan yet, so there is nothing to pay.</p></section>
    <?php endif; ?>

    <?php foreach ($rows as $r): ?>
        <section class="msg-card">
            <h2><?= e($r['label']) ?></h2>
            <p class="muted">
                <?php if ($r['covered']): ?>
                    <strong>Paid up<?= $r['until'] !== null && $r['until'] !== '9999-12-31' ? ' until ' . e($r['until']) : '' ?></strong>
                    <?= $r['subscribed'] !== null ? ' · ' . e($periods[$r['subscribed']]) : '' ?>
                <?php else: ?>
                    <strong>Not paid</strong>: the module stays off until payment is confirmed
                <?php endif; ?>
            </p>

            <?php if ($r['open'] !== null && $r['open']['status'] === 'pending_review'): ?>
                <p class="alert">A receipt for <?= e($money((float) $r['open']['amount'])) ?> (<?= e($periods[$r['open']['period']]) ?>) is waiting for the superadmin to check.</p>
            <?php elseif ($r['open'] !== null && $r['open']['note'] !== null): ?>
                <p class="alert bad">The last receipt was not accepted: <?= e($r['open']['note']) ?></p>
            <?php endif; ?>

            <table class="msg-table">
                <thead><tr><th>Plan</th><th>Price</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($r['quotes'] as $pk => $q): ?>
                        <tr>
                            <td><?= e($periods[$pk]) ?></td>
                            <td>
                                <?php if ($q['kind'] !== null): ?>
                                    <s><?= e($money($q['list'])) ?></s> <strong><?= e($money($q['final'])) ?></strong>
                                    <small class="muted">(<?= e(\App\Services\ModulePricing::describe(['kind' => $q['kind'], 'value' => $q['value']])) ?> until <?= e(date('d M Y, H:i', strtotime((string) $q['ends_at']))) ?>)</small>
                                <?php else: ?>
                                    <strong><?= e($money($q['list'])) ?></strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="<?= e(url('/admin/billing/' . $r['key'] . '/pay')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="period" value="<?= e($pk) ?>">
                                    <button class="btn sm" type="submit" <?= $billplzReady ? '' : 'disabled' ?>>Pay by Billplz</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!$billplzReady): ?><p class="muted">Card payment is not set up yet.</p><?php endif; ?>

            <?php if ($bank !== null): ?>
                <form method="post" action="<?= e(url('/admin/billing/' . $r['key'] . '/receipt')) ?>" enctype="multipart/form-data" class="card-form">
                    <?= csrf_field() ?>
                    <p class="muted">Or transfer to <?= e($bank['name']) ?> · <?= e($bank['number']) ?> · <?= e($bank['holder']) ?>, then upload the receipt (JPG, PNG or PDF, up to 5 MB).</p>
                    <label>Plan
                        <select name="period">
                            <?php foreach ($r['quotes'] as $pk => $q): ?>
                                <option value="<?= e($pk) ?>"><?= e($periods[$pk]) ?> · <?= e($money($q['final'])) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                    <input type="file" name="receipt" accept="image/jpeg,image/png,application/pdf" required>
                    <button class="btn secondary" type="submit">Upload receipt</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>
