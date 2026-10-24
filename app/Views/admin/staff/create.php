<div class="msg-page">
    <header class="msg-head">
        <div>
            <p class="muted" style="margin:0"><a href="<?= e(url('/admin/staff')) ?>">← Staff</a></p>
            <h1>Add staff</h1>
            <p class="muted">Give them a temporary password and tick only what they should be able to do.</p>
        </div>
    </header>

    <section class="msg-card">
        <form method="post" action="<?= e(url('/admin/staff')) ?>" class="card-form" novalidate data-busy-form>
            <?= csrf_field() ?>
            <label>Full name <input name="name" value="<?= old('name') ?>" required><?= field_error('name') ?></label>
            <label>Email <input type="email" name="email" value="<?= old('email') ?>" required><?= field_error('email') ?></label>
            <label>Phone (optional) <input name="phone" value="<?= old('phone') ?>"><?= field_error('phone') ?></label>
            <label>Temporary password (min 8) <input type="password" name="password" autocomplete="new-password" required><?= field_error('password') ?></label>
            <fieldset>
                <legend>Permissions</legend>
                <?php foreach ($permissions as $key => $label): ?>
                    <label class="check"><input type="checkbox" name="permissions[]" value="<?= e($key) ?>"> <?= e($label) ?></label>
                <?php endforeach; ?>
            </fieldset>
            <div class="msg-row"><a href="<?= e(url('/admin/staff')) ?>">Cancel</a><button class="btn">Create staff</button></div>
        </form>
    </section>
</div>
