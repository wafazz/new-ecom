<?php
$me = \App\Core\Auth::user();
$role = $me['role'];
$fmt = fn(?string $d) => $d ? date('d M Y', strtotime($d)) : '—';
$active = 'profile';
?>
<div class="acct-hero">
    <span class="acct-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $me['name'], 0, 1))) ?></span>
    <div>
        <h1><?= e($me['name']) ?></h1>
        <p class="muted"><?= e($me['email']) ?> · <span class="badge st-processing"><?= e(ucfirst($role)) ?></span></p>
    </div>
</div>
<?php require __DIR__ . '/_tabs.php'; ?>

<div class="acct-grid">
    <section class="panel acct-card">
        <h2>Your details</h2>
        <form method="post" action="<?= e(url($base . '/profile')) ?>" novalidate>
            <?= csrf_field() ?>
            <label>Full name
                <input name="name" value="<?= old('name', (string) $me['name']) ?>" autocomplete="name" maxlength="120" required><?= field_error('name') ?></label>
            <label>Phone <small class="muted">(optional)</small>
                <input name="phone" value="<?= old('phone', (string) $me['phone']) ?>" autocomplete="tel" inputmode="tel" maxlength="30" placeholder="+60 12-345 6789"><?= field_error('phone') ?></label>
            <label>Email
                <input value="<?= e($me['email']) ?>" disabled aria-describedby="email-note">
                <small class="muted" id="email-note">This is your login. To change it, ask <?= $role === 'admin' ? 'another administrator or the person who set up the shop' : 'an administrator' ?>.</small></label>
            <p class="acct-actions"><button class="btn">Save changes</button></p>
        </form>
    </section>

    <section class="panel acct-card">
        <h2>Account</h2>
        <dl class="acct-facts">
            <div><dt>Role</dt><dd><?= e(ucfirst($role)) ?></dd></div>
            <div><dt>Status</dt><dd><span class="badge <?= $me['status'] === 'active' ? 'active' : 'draft' ?>"><?= e(ucfirst((string) $me['status'])) ?></span></dd></div>
            <div><dt><?= $role === 'member' ? 'Member since' : 'Account created' ?></dt><dd><?= e($fmt($me['created_at'])) ?></dd></div>
            <div><dt>Last sign-in</dt><dd><?= e($me['last_login_at'] ? date('d M Y, H:i', strtotime((string) $me['last_login_at'])) : '—') ?></dd></div>
        </dl>
        <?php if ($role === 'admin'): ?>
            <h3>Access</h3><p class="muted">Administrators can use every part of the back office.</p>
        <?php elseif ($role === 'staff'): ?>
            <h3>What you can do</h3>
            <?php if ($perms === []): ?><p class="muted">No extra permissions yet. Ask an administrator if you need access to something.</p>
            <?php else: ?><ul class="perm-chips"><?php foreach ($perms as $label): ?><li><?= e($label) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php else: ?>
            <h3>Quick links</h3>
            <p class="acct-links"><a href="<?= e(url('/member/orders')) ?>">My orders</a> · <a href="<?= e(url('/member/addresses')) ?>">Addresses</a> · <a href="<?= e(url('/member/wishlist')) ?>">Wishlist</a></p>
        <?php endif; ?>
        <p class="acct-links"><a class="btn secondary sm" href="<?= e(url($base . '/password')) ?>">Change password →</a></p>
    </section>
</div>
