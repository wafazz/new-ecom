<?php
/** Expects $rows (paid modules: key, label, quotes per plan, until, subscribed, covered, open invoice), $bank, $billplzReady. */
$money = fn(float $v): string => 'RM ' . number_format($v, 2);
$periods = \App\Services\ModulePricing::PERIODS;
?>
<div class="msg-page bill-page">
    <header class="msg-head">
        <div>
            <h1>Module billing</h1>
            <p class="muted">Pick a plan for each module, then pay by Billplz or send a bank transfer receipt. A paid module stays on while it is paid up.</p>
        </div>
    </header>

    <?php if ($rows === []): ?>
        <section class="msg-card"><p class="muted">No module has a plan yet, so there is nothing to pay.</p></section>
    <?php endif; ?>

    <div class="bill-grid">
        <?php foreach ($rows as $r):
            $first = array_key_first($r['quotes']);
            $status = $r['covered'] ? 'paid' : ($r['open'] !== null && $r['open']['status'] === 'pending_review' ? 'review' : 'unpaid');
        ?>
            <article class="bill-card">
                <header class="bill-head">
                    <h2><?= e($r['label']) ?></h2>
                    <span class="bill-status is-<?= e($status) ?>">
                        <?= $status === 'paid' ? 'Paid up' . ($r['until'] !== null && $r['until'] !== '9999-12-31' ? ' to ' . e($r['until']) : '') : ($status === 'review' ? 'Receipt in review' : 'Not paid') ?>
                    </span>
                </header>

                <?php if ($status === 'paid' && $r['until'] !== null && $r['until'] !== '9999-12-31'):
                    $endMs = strtotime((string) $r['until'] . ' 23:59:59') * 1000;
                ?>
                    <div class="bill-count" data-end-ms="<?= (int) $endMs ?>" role="timer" aria-label="Time left on this plan">
                        <span class="bill-count-label">Time left</span>
                        <span class="bill-count-value" data-count>--</span>
                        <small class="muted bill-count-end">Ends <?= e(date('d M Y, H:i', $endMs / 1000)) ?></small>
                    </div>
                <?php elseif ($status === 'paid'): ?>
                    <div class="bill-count is-lifetime" role="status"><span class="bill-count-label">No end date</span></div>
                <?php endif; ?>

                <?php if ($status === 'paid' && $r['subscribed'] !== null): ?>
                    <p class="muted bill-sub">Current plan: <?= e($periods[$r['subscribed']]) ?></p>
                <?php elseif ($status === 'unpaid'): ?>
                    <p class="muted bill-sub">The module stays off until payment is confirmed.</p>
                <?php endif; ?>

                <?php if ($r['open'] !== null && $r['open']['status'] === 'pending_review'): ?>
                    <p class="bill-note">A receipt for <?= e($money((float) $r['open']['amount'])) ?> (<?= e($periods[$r['open']['period']]) ?>) is waiting for the superadmin to check.</p>
                <?php elseif ($r['open'] !== null && $r['open']['note'] !== null): ?>
                    <p class="bill-note is-bad">The last receipt was not accepted: <?= e($r['open']['note']) ?></p>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" class="bill-form" data-bill-form>
                    <?= csrf_field() ?>
                    <fieldset class="bill-plans">
                        <legend>Choose a plan</legend>
                        <?php foreach ($r['quotes'] as $pk => $q): ?>
                            <label class="bill-plan">
                                <input type="radio" name="period" value="<?= e($pk) ?>" <?= $pk === $first ? 'checked' : '' ?>>
                                <span class="bill-plan-name"><?= e($periods[$pk]) ?></span>
                                <span class="bill-price">
                                    <?php if ($q['kind'] !== null): ?>
                                        <s><?= e($money($q['list'])) ?></s> <strong><?= e($money($q['final'])) ?></strong>
                                        <small class="bill-disc"><?= e(\App\Services\ModulePricing::describe(['kind' => $q['kind'], 'value' => $q['value']])) ?></small>
                                        <span class="bill-disc-timer" data-end-ms="<?= (int) (strtotime((string) $q['ends_at']) * 1000) ?>" role="timer">--</span>
                                    <?php else: ?>
                                        <strong><?= e($money($q['list'])) ?></strong>
                                    <?php endif; ?>
                                </span>
                                <span class="bill-tick" aria-hidden="true"></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                    <div class="bill-actions">
                        <button class="btn bill-pay" type="submit" formaction="<?= e(url('/admin/billing/' . $r['key'] . '/pay')) ?>" <?= $billplzReady ? '' : 'disabled' ?>>Pay by Billplz</button>
                        <?php if (!$billplzReady): ?><small class="muted">Card payment is not set up yet.</small><?php endif; ?>
                    </div>

                    <?php if ($bank !== null): ?>
                        <details class="bill-receipt">
                            <summary>Or pay by bank transfer</summary>
                            <p class="muted">Transfer to <strong><?= e($bank['name']) ?></strong> · <?= e($bank['number']) ?> · <?= e($bank['holder']) ?>, then upload the receipt.</p>
                            <label class="bill-file">
                                <span>Receipt (JPG, PNG or PDF, up to 5 MB)</span>
                                <input type="file" name="receipt" accept="image/jpeg,image/png,application/pdf" data-file-input>
                            </label>
                            <p class="muted bill-filename" data-file-name aria-live="polite"></p>
                            <button class="btn secondary" type="submit" formaction="<?= e(url('/admin/billing/' . $r['key'] . '/receipt')) ?>">Upload receipt</button>
                        </details>
                    <?php endif; ?>
                </form>
            </article>
        <?php endforeach; ?>
    </div>
</div>
