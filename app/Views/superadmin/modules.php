<?php
/** Expects $modules (Modules::all()), $audit (rows: when, who, what) and $scripts for modules.js. */
$onCount = count(array_filter($modules, fn($m) => $m['enabled']));
?>
<div class="msg-page mod-page" data-modules>
    <header class="msg-head">
        <div>
            <h1>Modules</h1>
            <p class="muted">Switch whole features on or off for the shop. Only superadmins can see this page. Admins run the back office but cannot change these switches.</p>
        </div>
        <p class="mod-summary" data-count aria-live="polite"><?= $onCount ?> of <?= count($modules) ?> on</p>
    </header>

    <?php if (empty($pinCustom)): ?>
        <section class="msg-card pin-nudge" role="status">
            <h2>Change your PIN</h2>
            <p>You are using the default PIN, 123456. Anyone who knows it can switch modules. <a href="<?= e(url('/superadmin/security')) ?>">Set your own 6-digit PIN now</a>.</p>
        </section>
    <?php endif; ?>

    <section class="msg-card">
        <h2>Discounts on module prices</h2>
        <p class="muted">A discount sets every paid module to a percentage off, or to a fixed price, until the end time. A module's own discount (below) takes priority over this one while both are active. A fixed price never raises a price. A discount applies to invoices created while it runs; invoices already issued keep their price.</p>
        <?php if (!empty($global) && $global['active']): ?>
            <p><strong>All modules: <?= e(\App\Services\ModulePricing::describe($global)) ?> until <?= e(date('d M Y, H:i', strtotime($global['ends_at']))) ?></strong></p>
        <?php else: ?>
            <p class="muted">No discount on all modules.</p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/superadmin/discounts')) ?>" class="mod-price">
            <?= csrf_field() ?>
            <input type="hidden" name="scope" value="global">
            <label>Type <select name="kind"><option value="percent">Percentage off</option><option value="fixed">Fixed price (RM)</option></select></label>
            <label>Value <input name="value" inputmode="decimal" placeholder="20 or 50.00" required></label>
            <label>Ends on <input type="datetime-local" name="ends_at" required></label>
            <button class="btn sm" type="submit">Set discount on all</button>
        </form>
        <?php if (!empty($global)): ?>
            <form method="post" action="<?= e(url('/superadmin/discounts')) ?>"><?= csrf_field() ?><input type="hidden" name="scope" value="global"><input type="hidden" name="clear" value="1"><button class="btn secondary sm" type="submit">Remove all-module discount</button></form>
        <?php endif; ?>
    </section>

    <section class="msg-card">
        <h2>Feature switches</h2>
        <div class="mod-grid" data-module-list>
            <?php foreach ($modules as $m):
                $periodsLabel = \App\Services\ModulePricing::PERIODS;
                $hasPlans = !empty($m['plans']);
                $ownDesc = $m['own_discount'] !== null ? \App\Services\ModulePricing::describe(['kind' => $m['own_discount']['kind'], 'value' => $m['own_discount']['value']]) : '';
            ?>
                <article class="mod-card" data-module="<?= e($m['key']) ?>" data-label="<?= e($m['label']) ?>" data-enabled="<?= $m['enabled'] ? '1' : '0' ?>">
                    <div class="mod-head">
                        <h3 class="mod-name"><?= e($m['label']) ?><?php if ($m['off_by_default']): ?> <small class="muted">· off by default</small><?php endif; ?></h3>
                        <span class="mod-status <?= $m['enabled'] ? 'is-on' : 'is-off' ?>"><?= $m['enabled'] ? 'On' : ($m['waiting'] ? 'Waiting for payment' : 'Off') ?></span>
                    </div>
                    <p class="muted mod-help"><?= e($m['help']) ?></p>

                    <form method="post" action="<?= e(url('/superadmin/modules/' . $m['key'])) ?>" class="mod-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="enabled" value="<?= $m['enabled'] ? '0' : '1' ?>">
                        <noscript><label class="mod-pin-fallback">Your PIN <input type="password" name="pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" required></label></noscript>
                        <button type="submit" class="mod-switch" role="switch"
                                aria-checked="<?= $m['enabled'] ? 'true' : 'false' ?>"
                                aria-label="<?= e($m['label']) ?>: <?= $m['enabled'] ? 'on, switch off' : 'off, switch on' ?>">
                            <span class="mod-track" aria-hidden="true"><span class="mod-knob"></span></span>
                            <span class="mod-switch-text"><?= $m['enabled'] ? 'Switch off' : 'Switch on' ?></span>
                        </button>
                    </form>

                    <details class="mod-settings">
                        <summary>
                            Pricing &amp; discounts
                            <small class="muted"><?= $hasPlans ? count($m['plans']) . ' plan' . (count($m['plans']) === 1 ? '' : 's') : 'free' ?><?= $ownDesc !== '' ? ' · ' . e($ownDesc) . ' discount' : '' ?></small>
                        </summary>

                        <form method="post" action="<?= e(url('/superadmin/modules/' . $m['key'] . '/price')) ?>" class="mod-price mod-plans-form" data-plans-form>
                            <?= csrf_field() ?>
                            <div class="mod-plan-grid">
                                <?php foreach ($periodsLabel as $pk => $pl):
                                    $cur = isset($m['plans'][$pk]) ? number_format($m['plans'][$pk], 2, '.', '') : '';
                                    $q = $m['quotes'][$pk] ?? null;
                                    $dKind = $q['kind'] ?? '';
                                    $dVal = $q !== null && $q['kind'] !== null ? (string) $q['value'] : '';
                                ?>
                                    <label class="mod-plan">
                                        <span class="mod-plan-name"><?= e($pl) ?></span>
                                        <span class="mod-money"><span aria-hidden="true">RM</span>
                                            <input name="<?= e($pk) ?>" inputmode="decimal" value="<?= e($cur) ?>" placeholder="not offered"
                                                   data-plan-input data-initial="<?= e($cur) ?>" data-disc-kind="<?= e((string) $dKind) ?>" data-disc-value="<?= e($dVal) ?>"
                                                   aria-label="<?= e($pl) ?> price for <?= e($m['label']) ?>"></span>
                                        <small class="mod-final" data-final aria-live="polite"><?= $cur === '' ? 'Not offered' : ($q && $q['kind'] !== null ? 'Now RM' . e(number_format($q['final'], 2)) : '') ?></small>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="mod-actions">
                                <p class="muted mod-hint">Leave a plan blank to stop offering it.</p>
                                <button class="btn sm" type="submit" data-save-plans disabled>Save plans</button>
                            </div>
                        </form>

                        <?php if ($hasPlans): ?>
                            <div class="mod-discount">
                                <h4>Discount for this module</h4>
                                <?php if ($m['own_discount'] !== null): ?>
                                    <p class="muted">Now: <strong><?= e($ownDesc) ?></strong> until <?= e(date('d M Y, H:i', strtotime((string) $m['own_discount']['ends_at']))) ?><?= $m['own_discount']['active'] ? '' : ' (ended)' ?></p>
                                    <form method="post" action="<?= e(url('/superadmin/discounts')) ?>" class="mod-plain"><?= csrf_field() ?><input type="hidden" name="scope" value="<?= e($m['key']) ?>"><input type="hidden" name="clear" value="1"><button class="btn secondary sm" type="submit">Remove this discount</button></form>
                                <?php endif; ?>
                                <form method="post" action="<?= e(url('/superadmin/discounts')) ?>" class="mod-price mod-discount-form" data-discount-form>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="scope" value="<?= e($m['key']) ?>">
                                    <div class="mod-seg" role="radiogroup" aria-label="Discount type for <?= e($m['label']) ?>">
                                        <label><input type="radio" name="kind" value="percent" checked><span>% off</span></label>
                                        <label><input type="radio" name="kind" value="fixed"><span>RM fixed price</span></label>
                                    </div>
                                    <label class="mod-field">Value
                                        <input name="value" inputmode="decimal" placeholder="e.g. 20" data-value-input aria-label="Discount value for <?= e($m['label']) ?>"></label>
                                    <label class="mod-field">Ends
                                        <input type="datetime-local" name="ends_at" data-ends aria-label="Discount end for <?= e($m['label']) ?>"></label>
                                    <div class="mod-presets" role="group" aria-label="Quick end dates">
                                        <button type="button" class="mod-chip" data-days="1">1 day</button>
                                        <button type="button" class="mod-chip" data-days="7">7 days</button>
                                        <button type="button" class="mod-chip" data-days="30">30 days</button>
                                    </div>
                                    <button class="btn sm" type="submit">Set discount</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </details>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="msg-card">
        <h2>Recent changes</h2>
        <div data-audit>
            <?php if ($audit === []): ?>
                <p class="muted">No module changes yet.</p>
            <?php else: ?>
                <ol class="mod-log">
                    <?php foreach ($audit as $a): ?>
                        <li><span class="mod-log-what"><?= e($a['what']) ?></span> <span class="muted"><?= e($a['who']) ?> · <?= e($a['when']) ?></span></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </section>
</div>
