<?php
/** Expects $rows (priced modules with price, paid-until, covered, open invoice), $bank (array|null), $billplzReady (bool). */
$money = fn(float $v): string => 'RM ' . number_format($v, 2);
$periodLabel = fn(string $p): string => ['one_time' => 'one-time', 'monthly' => 'per month', 'yearly' => 'per year'][$p] ?? $p;
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Module billing</h1>
            <p class="muted">Paid modules stay on while they are paid up. Pay by Billplz, or transfer to the bank account below and upload the receipt. The superadmin checks each receipt.</p>
        </div>
    </header>

    <?php if ($rows === []): ?>
        <section class="msg-card"><p class="muted">No module has a price yet, so there is nothing to pay.</p></section>
    <?php endif; ?>

    <?php foreach ($rows as $r): ?>
        <section class="msg-card">
            <h2><?= e($r['label']) ?></h2>
            <p class="muted"><?= e($money($r['price']['amount'])) ?> <?= e($periodLabel($r['price']['period'])) ?>
                · <?php if ($r['covered']): ?><strong>Paid up<?= $r['until'] !== null && $r['until'] !== '9999-12-31' ? ' until ' . e($r['until']) : '' ?></strong><?php else: ?><strong>Not paid</strong> — the module stays off until payment is confirmed<?php endif; ?></p>

            <?php if ($r['open'] !== null && $r['open']['status'] === 'pending_review'): ?>
                <p class="alert">A receipt for <?= e($money((float) $r['open']['amount'])) ?> is waiting for the superadmin to check.</p>
            <?php elseif ($r['open'] !== null && $r['open']['status'] === 'unpaid' && $r['open']['note'] !== null): ?>
                <p class="alert bad">The last receipt was not accepted: <?= e($r['open']['note']) ?></p>
            <?php endif; ?>

            <?php if (!$r['covered'] || $r['open'] !== null): ?>
                <div class="msg-table-wrap">
                    <form method="post" action="<?= e(url('/admin/billing/' . $r['key'] . '/pay')) ?>" class="inline-form">
                        <?= csrf_field() ?>
                        <button class="btn" type="submit" <?= $billplzReady ? '' : 'disabled' ?>>Pay with Billplz</button>
                        <?php if (!$billplzReady): ?><small class="muted">Not set up yet.</small><?php endif; ?>
                    </form>
                    <?php if ($bank !== null): ?>
                        <form method="post" action="<?= e(url('/admin/billing/' . $r['key'] . '/receipt')) ?>" enctype="multipart/form-data" class="card-form">
                            <?= csrf_field() ?>
                            <p class="muted">Transfer to <?= e($bank['name']) ?> · <?= e($bank['number']) ?> · <?= e($bank['holder']) ?>, then upload the receipt (JPG, PNG or PDF, up to 5 MB).</p>
                            <input type="file" name="receipt" accept="image/jpeg,image/png,application/pdf" required>
                            <button class="btn secondary" type="submit">Upload receipt</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>
