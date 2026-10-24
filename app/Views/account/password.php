<?php $active = 'password'; ?>
<div class="acct-hero">
    <span class="acct-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) \App\Core\Auth::user()['name'], 0, 1))) ?></span>
    <div><h1>Password</h1><p class="muted">Keep your account safe with a strong password you do not use anywhere else.</p></div>
</div>
<?php require __DIR__ . '/_tabs.php'; ?>

<div class="acct-grid">
    <section class="panel acct-card">
        <h2>Change password</h2>
        <form method="post" action="<?= e(url($base . '/password')) ?>" novalidate data-password-form>
            <?= csrf_field() ?>
            <?php /* Browsers and password managers: the account email is the username for this form. */ ?>
            <input type="text" name="username" value="<?= e(\App\Core\Auth::user()['email']) ?>" autocomplete="username" hidden aria-hidden="true" tabindex="-1">
            <label>Current password
                <span class="pw"><input type="password" name="current_password" autocomplete="current-password" required><button type="button" class="pw-toggle" data-pw-toggle aria-label="Show password" hidden>Show</button></span>
                <?= field_error('current_password') ?></label>
            <label>New password
                <span class="pw"><input type="password" name="password" autocomplete="new-password" minlength="8" maxlength="72" required aria-describedby="pw-hint"><button type="button" class="pw-toggle" data-pw-toggle aria-label="Show password" hidden>Show</button></span>
                <span class="pw-meter" data-pw-meter hidden><i></i><i></i><i></i><i></i></span>
                <small class="muted" id="pw-hint" data-pw-hint>At least 8 characters. A longer phrase is stronger than a short, complicated one.</small>
                <?= field_error('password') ?></label>
            <label>Confirm new password
                <span class="pw"><input type="password" name="password_confirmation" autocomplete="new-password" required><button type="button" class="pw-toggle" data-pw-toggle aria-label="Show password" hidden>Show</button></span>
                <small class="pw-match" data-pw-match hidden></small>
                <?= field_error('password_confirmation') ?></label>
            <p class="acct-actions"><button class="btn">Update password</button> <a href="<?= e(url($base . '/profile')) ?>">Cancel</a></p>
        </form>
    </section>

    <section class="panel acct-card">
        <h2>Tips</h2>
        <ul class="tips">
            <li>Use 12 or more characters: a few random words works well.</li>
            <li>Do not reuse a password from another site.</li>
            <li>Never share it. Nobody from the shop will ask for it.</li>
            <li>After you change it, you stay signed in on this device.</li>
        </ul>
    </section>
</div>
