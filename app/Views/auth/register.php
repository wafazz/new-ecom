<h1>Create account</h1>
<form method="post" action="<?= e(url('/register')) ?>" novalidate>
    <?= csrf_field() ?>
    <label>Full name
        <input name="name" value="<?= old('name') ?>" autocomplete="name" required autofocus>
        <?= field_error('name') ?>
    </label>
    <label>Email
        <input type="email" name="email" value="<?= old('email') ?>" autocomplete="email" required>
        <?= field_error('email') ?>
    </label>
    <label>Phone (optional)
        <input name="phone" value="<?= old('phone') ?>" autocomplete="tel">
        <?= field_error('phone') ?>
    </label>
    <label>Password (min 8)
        <input type="password" name="password" autocomplete="new-password" required>
        <?= field_error('password') ?>
    </label>
    <label>Confirm password
        <input type="password" name="password_confirmation" autocomplete="new-password" required>
        <?= field_error('password_confirmation') ?>
    </label>
    <button class="s-btn lg block">Create account</button>
</form>
<p class="muted">Already registered? <a href="<?= e(url('/login')) ?>">Log in</a></p>
