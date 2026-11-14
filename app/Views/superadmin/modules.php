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
            <?php foreach ($modules as $m): ?>
                <article class="mod-card" data-module="<?= e($m['key']) ?>" data-label="<?= e($m['label']) ?>" data-enabled="<?= $m['enabled'] ? '1' : '0' ?>">
                    <div class="mod-head">
                        <h3 class="mod-name"><?= e($m['label']) ?><?php if ($m['off_by_default']): ?> <small class="muted">· off by default</small><?php endif; ?></h3>
                        <span class="mod-status <?= $m['enabled'] ? 'is-on' : 'is-off' ?>"><?= $m['enabled'] ? 'On' : ($m['waiting'] ? 'Waiting for payment' : 'Off') ?></span>
                    </div>
                    <p class="muted mod-help"><?= e($m['help']) ?></p>
                    <?php if (!empty($m['plans'])): ?>
                        <div class="mod-plans">
                            <p class="muted">Plans:</p>
                            <ul class="mod-plan-list">
                                <?php foreach ($m['quotes'] as $pk => $q): ?>
                                    <li>
                                        <?= e(\App\Services\ModulePricing::PERIODS[$pk]) ?>:
                                        <?php if ($q['kind'] !== null): ?>
                                            <s>RM<?= e(number_format($q['list'], 2)) ?></s> <strong>RM<?= e(number_format($q['final'], 2)) ?></strong>
                                            (<?= e(\App\Services\ModulePricing::describe(['kind' => $q['kind'], 'value' => $q['value']])) ?> until <?= e(date('d M Y, H:i', strtotime((string) $q['ends_at']))) ?>)
                                        <?php else: ?>
                                            <strong>RM<?= e(number_format($q['list'], 2)) ?></strong>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <form method="post" action="<?= e(url('/superadmin/modules/' . $m['key'] . '/price')) ?>" class="mod-price" data-no-busy>
                        <?= csrf_field() ?>
                        <?php foreach (\App\Services\ModulePricing::PERIODS as $pk => $pl): ?>
                            <label><?= e($pl) ?> (RM, blank = not offered)
                                <input name="<?= e($pk) ?>" inputmode="decimal" value="<?= e(isset($m['plans'][$pk]) ? number_format($m['plans'][$pk], 2, '.', '') : '') ?>" aria-label="<?= e($pl) ?> price for <?= e($m['label']) ?>"></label>
                        <?php endforeach; ?>
                        <button class="btn secondary sm" type="submit">Save plans</button>
                    </form>
                    <?php if (!empty($m['plans'])): ?>
                    <form method="post" action="<?= e(url('/superadmin/discounts')) ?>" class="mod-price">
                        <?= csrf_field() ?>
                        <input type="hidden" name="scope" value="<?= e($m['key']) ?>">
                        <label>This module: <select name="kind" aria-label="Discount type for <?= e($m['label']) ?>"><option value="percent">Percentage off</option><option value="fixed">Fixed price (RM)</option></select></label>
                        <label>Value <input name="value" inputmode="decimal" placeholder="none" aria-label="Discount value for <?= e($m['label']) ?>"></label>
                        <label>Ends on <input type="datetime-local" name="ends_at" aria-label="Discount end for <?= e($m['label']) ?>"></label>
                        <button class="btn secondary sm" type="submit">Set discount</button>
                    </form>
                    <?php if (!empty($m['own_discount'])): ?>
                        <form method="post" action="<?= e(url('/superadmin/discounts')) ?>"><?= csrf_field() ?><input type="hidden" name="scope" value="<?= e($m['key']) ?>"><input type="hidden" name="clear" value="1"><button class="btn secondary sm" type="submit">Remove this discount</button></form>
                    <?php endif; ?>
                    <?php endif; ?>
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
