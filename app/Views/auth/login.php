<h1><?= e($portalName) ?> login</h1>
<form method="post" action="<?= e(url($action)) ?>" novalidate>
    <?= csrf_field() ?>
    <label>Email
        <input type="email" name="email" value="<?= old('email') ?>" autocomplete="username" required autofocus>
        <?= field_error('email') ?>
    </label>
    <label>Password
        <input type="password" name="password" autocomplete="current-password" required>
        <?= field_error('password') ?>
    </label>
    <button class="s-btn lg block">Log in</button>
</form>
<?php if ($showRegister): ?>
    <p class="muted">No account? <a href="<?= e(url('/register')) ?>">Create one</a></p>
<?php endif; ?>
