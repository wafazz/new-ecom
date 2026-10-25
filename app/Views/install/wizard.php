<?php
/** Expects $checks, $passed, $tokenReady, $checked. */
require BASE_PATH . '/app/Views/partials/flash.php'; // messages and errors from the steps below
?>
<div class="install">
    <header class="install-head">
        <span class="install-mark" aria-hidden="true">✓</span>
        <div>
            <h1>Set up your shop</h1>
            <p class="muted">This takes about two minutes. It is available only until the shop is set up.</p>
        </div>
    </header>

    <ol class="install-steps" aria-label="Setup steps">
        <li class="<?= $passed ? 'done' : 'now' ?>"><span>1</span> Check the server</li>
        <li class="<?= $checked ? 'now' : '' ?>"><span>2</span> Create the admin and shop defaults</li>
    </ol>

    <section class="panel install-card">
        <h2>1. Server check</h2>
        <ul class="install-checks">
            <?php foreach ($checks as $c): ?>
                <li class="<?= $c['ok'] ? 'ok' : ($c['required'] ? 'bad' : 'warn') ?>">
                    <span class="install-dot" aria-hidden="true"></span>
                    <div>
                        <strong><?= e($c['label']) ?></strong>
                        <small class="muted"><?= e($c['detail']) ?></small>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if (!$passed): ?>
            <p class="alert bad">Some required checks are failing (marked red above). Fix them in <code>.env</code> or on the server, then reload this page.</p>
        <?php endif; ?>

        <?php if (!$tokenReady): ?>
            <p class="alert bad">Add a random <code>INSTALL_TOKEN</code> (16 or more characters) to <code>.env</code>, then reload this page.</p>
        <?php else: ?>
        <form method="post" action="<?= e(url('/install/check')) ?>" class="card-form" novalidate>
            <?= csrf_field() ?>
            <label>Install token (from INSTALL_TOKEN in .env)
                <input type="password" name="token" autocomplete="off" required>
            </label>
            <?= field_error('token') ?>
            <button class="btn" <?= $passed ? '' : 'disabled' ?>>Run checks and prepare the database</button>
        </form>
        <?php endif; ?>
    </section>

    <?php if ($checked): ?>
    <section class="panel install-card">
        <h2>2. Admin account and shop defaults</h2>
        <p class="muted">The shop name comes from <code>APP_NAME</code> in <code>.env</code>. Sender details, bank account and WhatsApp are set later on their own pages.</p>
        <form method="post" action="<?= e(url('/install/finish')) ?>" class="card-form" novalidate>
            <?= csrf_field() ?>
            <div class="grid2">
                <label>Admin name <input name="admin_name" required maxlength="120"></label>
                <label>Admin email <input name="admin_email" type="email" required></label>
                <label>Password (8+ characters) <input name="admin_password" type="password" autocomplete="new-password" required minlength="8"></label>
                <label>Confirm password <input name="admin_password_confirm" type="password" autocomplete="new-password" required minlength="8"></label>
                <p class="muted">Postage is set later under Shipping rates in the admin area, by weight and zone.</p>
                <label>Default affiliate commission (% of sales after shipping) <input name="commission_rate" inputmode="decimal" value="10"></label>
            </div>
            <label class="check"><input type="checkbox" name="guest_checkout" value="1" checked> Allow guest checkout</label>
            <label>Install token (enter again) <input type="password" name="token" autocomplete="off" required></label>
            <?= field_error('token') ?>
            <button class="btn">Finish setup</button>
        </form>
    </section>
    <?php endif; ?>
</div>
