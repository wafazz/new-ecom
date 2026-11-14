<?php
/** Expects $rows (paid modules: key, label, quotes per plan, until, subscribed, covered, open invoice), $bank, $billplzReady. */
$money = fn(float $v): string => 'RM ' . number_format($v, 2);
$periods = \App\Services\ModulePricing::PERIODS;
?>
<div class="msg-page bill-page">
    <header class="msg-head">
        <div>
            <h1>Buy Add-On Module</h1>
            <p class="muted">Pick a plan for each module, then pay by Billplz or send a bank transfer receipt. A paid module stays on while it is paid up.</p>
        </div>
    </header>

    <?php if ($rows === []): ?>
        <section class="msg-card"><p class="muted">No module has a plan yet, so there is nothing to pay.</p></section>
    <?php endif; ?>

    <?php
    // Sections, in order: modules the shop subscribes to (paid up or granted), modules with prices, modules without a price yet.
    $sections = [
        'subscribed' => ['Subscribed', []],
        'priced' => ['Has prices', []],
        'soon' => ['Not setting price yet', []],
    ];
    foreach ($rows as $row) {
        if (!empty($row['coming_soon'])) {
            $sections['soon'][1][] = $row;
        } elseif ($row['covered'] && $row['quotes'] !== []) {
            $sections['subscribed'][1][] = $row;
        } else {
            $sections['priced'][1][] = $row;
        }
    }
    ?>
    <?php foreach ($sections as $sectionKey => [$sectionTitle, $sectionRows]): if ($sectionRows === []) { continue; } ?>
    <section class="bill-section bill-section-<?= e($sectionKey) ?>" aria-labelledby="bill-sec-<?= e($sectionKey) ?>">
        <h2 class="bill-section-title" id="bill-sec-<?= e($sectionKey) ?>"><?= e($sectionTitle) ?> <small class="muted">(<?= count($sectionRows) ?>)</small></h2>
    <div class="bill-grid">
        <?php foreach ($sectionRows as $r):
            if (!empty($r['coming_soon'])):
        ?>
            <article class="bill-card bill-card-soon">
                <header class="bill-head">
                    <h2><?= e($r['label']) ?></h2>
                    <span class="bill-status is-soon">Coming soon</span>
                </header>
                <p class="bill-sub muted">No price has been set for this module yet, so it cannot be bought here. Plans will appear once the superadmin sets them.</p>
            </article>
        <?php
                continue;
            endif;
            $first = array_key_first($r['quotes']);
            $status = $r['covered'] ? 'paid' : ($r['open'] !== null && $r['open']['status'] === 'pending_review' ? 'review' : 'unpaid');
            // Payment controls: a lifetime plan needs none; a monthly plan can renew in its last 7 days, a yearly plan in its last 3 weeks.
            $isLifetime = $status === 'paid' && $r['until'] === '9999-12-31';
            $daysLeft = ($status === 'paid' && !$isLifetime && $r['until'] !== null) ? (int) ceil((strtotime((string) $r['until'] . ' 23:59:59') - time()) / 86400) : null;
            $window = match ($r['subscribed']) { 'monthly' => 7, 'yearly' => 21, default => null };
            // A module the superadmin has switched off can always be bought or renewed (lifetime has nothing to buy).
            $canPay = !$isLifetime && ($status !== 'paid' || !$r['switched_on'] || ($window !== null && $daysLeft !== null && $daysLeft <= $window));
        ?>
            <article class="bill-card">
                <header class="bill-head">
                    <h2><?= e($r['label']) ?></h2>
                    <span class="bill-status is-<?= e($status) ?>">
                        <?= $status === 'paid' ? 'Paid up' . ($r['until'] !== null && $r['until'] !== '9999-12-31' ? ' to ' . e($r['until']) : '') : ($status === 'review' ? 'Receipt in review' : 'Not paid') ?>
                    </span>
                </header>

                <?php if (!$r['switched_on'] && $status === 'paid' && !$isLifetime): ?>
                    <p class="bill-note">Switched off by the superadmin. You can renew now; the superadmin switches it back on.</p>
                <?php elseif ($isLifetime): ?>
                    <p class="bill-note">Lifetime plan. No payment is needed.</p>
                <?php elseif (!$canPay): ?>
                    <p class="bill-note">Renewal opens <?= $r['subscribed'] === 'yearly' ? '3 weeks' : '7 days' ?> before it ends<?= $r['until'] !== null ? ' (' . e(date('d M Y', strtotime((string) $r['until']))) . ')' : '' ?>.</p>
                <?php endif; ?>

                <?php if ($canPay): ?>
                <label class="bill-pick">
                    <input type="checkbox" form="bill-basket" name="pick[]" value="<?= e($r['key']) ?>" data-pick="<?= e($r['key']) ?>">
                    <span>Add to payment</span>
                </label>

                <?php if ($status === 'paid' && $r['until'] !== null && $r['until'] !== '9999-12-31'):
                    $endMs = strtotime((string) $r['until'] . ' 23:59:59') * 1000;
                ?>
                    <div class="flip-clock" data-end-ms="<?= (int) $endMs ?>" role="timer" aria-label="Time left on this plan">
                        <div class="flip-units" data-units>
                            <?php foreach (['D', 'H', 'M', 'S'] as $unit): ?>
                                <div class="flip-unit"><div class="flip-tile"><span class="flip-num">00</span></div><span class="flip-label"><?= e($unit) ?></span></div>
                            <?php endforeach; ?>
                        </div>
                        <small class="flip-end muted">Ends <?= e(date('d M Y, H:i', $endMs / 1000)) ?></small>
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
                                <input type="radio" name="period" value="<?= e($pk) ?>" data-final="<?= e(number_format($q['final'], 2, '.', '')) ?>" <?= $pk === $first ? 'checked' : '' ?>>
                                <span class="bill-plan-name"><?= e($periods[$pk]) ?></span>
                                <span class="bill-price">
                                    <?php if ($q['kind'] !== null): ?>
                                        <s><?= e($money($q['list'])) ?></s> <strong><?= e($money($q['final'])) ?></strong>
                                        <small class="bill-disc"><?= e(\App\Services\ModulePricing::describe(['kind' => $q['kind'], 'value' => $q['value']])) ?></small>
                                        <div class="flip-clock is-discount is-mini" data-end-ms="<?= (int) (strtotime((string) $q['ends_at']) * 1000) ?>" data-ended-text="Offer ended" role="timer" aria-label="Time left on this discount">
                                            <div class="flip-units" data-units>
                                                <?php foreach (['D', 'H', 'M', 'S'] as $unit): ?>
                                                    <div class="flip-unit"><div class="flip-tile"><span class="flip-num">00</span></div><span class="flip-label"><?= e($unit) ?></span></div>
                                                <?php endforeach; ?>
                                            </div>
                                            <small class="flip-end">Discount ends <?= e(date('d M Y, H:i', strtotime((string) $q['ends_at']))) ?></small>
                                        </div>
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
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    </section>
    <?php endforeach; ?>

    <form id="bill-basket" class="bill-basket" method="post" enctype="multipart/form-data" data-basket aria-label="Pay selected modules together">
        <?= csrf_field() ?>
        <div class="bill-basket-info">
            <strong data-basket-summary>Select modules to pay together.</strong>
            <ul class="bill-basket-list" data-basket-list aria-label="Selected modules"></ul>
            <span class="muted" data-basket-msg role="status" aria-live="polite"></span>
            <div class="bill-basket-total" aria-live="polite"><span>Total to pay</span><strong data-basket-total>RM 0.00</strong></div>
        </div>
        <div class="bill-basket-actions">
            <button class="btn bill-pay" type="submit" formaction="<?= e(url('/admin/billing/pay-selected')) ?>" data-basket-pay data-ready="<?= $billplzReady ? '1' : '0' ?>" disabled>Pay selected by Billplz</button>
            <?php if ($bank !== null): ?>
                <label class="bill-file"><span>One receipt for all selected (JPG, PNG or PDF)</span>
                    <input type="file" name="receipt" accept="image/jpeg,image/png,application/pdf" data-file-input></label>
                <p class="muted bill-filename" data-file-name aria-live="polite"></p>
                <button class="btn secondary" type="submit" formaction="<?= e(url('/admin/billing/receipt-selected')) ?>" data-basket-receipt disabled>Upload one receipt for selected</button>
            <?php endif; ?>
        </div>
    </form>
</div>
