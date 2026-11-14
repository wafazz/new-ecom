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

    <section class="msg-card">
        <h2>Discounts on module prices</h2>
        <p class="muted">A discount takes a percentage off the price of every paid module, until the end time. A module's own discount (below) takes priority over this one while both are active. A discount applies to invoices created while it runs; invoices already issued keep their price.</p>
        <?php if (!empty($global) && $global['active']): ?>
            <p><strong>All modules: <?= e(rtrim(rtrim(number_format($global['percent'], 2, '.', ''), '0'), '.')) ?>% off until <?= e(date('d M Y, H:i', strtotime($global['ends_at']))) ?></strong></p>
        <?php else: ?>
            <p class="muted">No discount on all modules.</p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/superadmin/discounts')) ?>" class="mod-price">
            <?= csrf_field() ?>
            <input type="hidden" name="scope" value="global">
            <label>Percent off <input name="percent" inputmode="decimal" placeholder="for example 20" required></label>
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
                    <?php if ($m['quote']['list'] > 0): ?>
                        <p class="muted">Price: <?= $m['quote']['percent'] > 0 ? '<s>RM' . e(number_format($m['quote']['list'], 2)) . '</s> <strong>RM' . e(number_format($m['quote']['final'], 2)) . '</strong> (' . e(rtrim(rtrim(number_format($m['quote']['percent'], 2, '.', ''), '0'), '.')) . '% off until ' . e(date('d M Y, H:i', strtotime((string) $m['quote']['ends_at']))) . ')' : '<strong>RM' . e(number_format($m['quote']['list'], 2)) . '</strong>' ?> <?= e(\App\Services\ModulePricing::PERIODS[$m['quote']['period']]) ?></p>
                    <?php endif; ?>
                    <form method="post" action="<?= e(url('/superadmin/modules/' . $m['key'] . '/price')) ?>" class="mod-price" data-no-busy>
                        <?= csrf_field() ?>
                        <label>Billing
                            <select name="period">
                                <?php foreach (\App\Services\ModulePricing::PERIODS as $pk => $pl): ?>
                                    <option value="<?= e($pk) ?>" <?= $m['price']['period'] === $pk ? 'selected' : '' ?>><?= e($pl) ?></option>
                                <?php endforeach; ?>
                            </select></label>
                        <label>Price (RM, 0 = free)
                            <input name="amount" inputmode="decimal" value="<?= e(number_format($m['price']['amount'], 2, '.', '')) ?>" aria-label="Price for <?= e($m['label']) ?>"></label>
                        <button class="btn secondary sm" type="submit">Save price</button>
                    </form>
                    <?php if ($m['quote']['list'] > 0): ?>
                    <form method="post" action="<?= e(url('/superadmin/discounts')) ?>" class="mod-price">
                        <?= csrf_field() ?>
                        <input type="hidden" name="scope" value="<?= e($m['key']) ?>">
                        <label>This module: percent off <input name="percent" inputmode="decimal" placeholder="none" aria-label="Discount for <?= e($m['label']) ?>"></label>
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
