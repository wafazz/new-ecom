<h1>Forgot password</h1>
<p class="muted">Enter the email you signed up with. We will send a link to choose a new password.</p>
<form method="post" action="<?= e(url('/forgot-password')) ?>" novalidate>
    <?= csrf_field() ?>
    <label>Email
        <input type="email" name="email" value="<?= old('email') ?>" autocomplete="username" required autofocus>
        <?= field_error('email') ?>
    </label>
    <button class="s-btn lg block">Send reset link</button>
</form>
<p class="muted"><a href="<?= e(url('/login')) ?>">Back to log in</a></p>
