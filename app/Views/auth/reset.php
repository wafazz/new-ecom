<h1>Choose a new password</h1>
<form method="post" action="<?= e(url('/reset-password')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <label>New password
        <input type="password" name="password" autocomplete="new-password" minlength="8" maxlength="72" required autofocus>
        <small class="muted">At least 8 characters.</small>
        <?= field_error('password') ?>
    </label>
    <label>Confirm new password
        <input type="password" name="password_confirmation" autocomplete="new-password" maxlength="72" required>
        <?= field_error('password_confirmation') ?>
    </label>
    <button class="s-btn lg block">Save new password</button>
</form>
