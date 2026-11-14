<?php
/** Expects $custom (whether this superadmin has set their own PIN). */
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Security</h1>
            <p class="muted">Your PIN is asked for every time you switch a module on or off. It is 6 digits. Keep it private. After 5 wrong tries it locks for 15 minutes.</p>
        </div>
    </header>

    <?php if (!$custom): ?>
        <section class="msg-card pin-nudge"><p>You are still using the default PIN, 123456. Set your own now: enter 123456 as the current PIN.</p></section>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/superadmin/security')) ?>" class="msg-card card-form" autocomplete="off">
        <?= csrf_field() ?>
        <label>Current PIN <input type="password" name="current" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></label>
        <label>New PIN (6 digits) <input type="password" name="pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></label>
        <label>Confirm new PIN <input type="password" name="confirm" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></label>
        <div class="msg-row"><span></span><button class="btn">Change PIN</button></div>
    </form>
</div>
