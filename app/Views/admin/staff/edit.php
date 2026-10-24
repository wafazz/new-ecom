<div class="msg-page">
    <header class="msg-head">
        <div>
            <p class="muted" style="margin:0"><a href="<?= e(url('/admin/staff')) ?>">← Staff</a></p>
            <h1>Permissions</h1>
            <p class="muted"><?= e($staff['name']) ?> · <?= e($staff['email']) ?></p>
        </div>
    </header>

    <section class="msg-card">
        <form method="post" action="<?= e(url('/admin/staff/' . (int) $staff['id'] . '/permissions')) ?>" class="card-form" novalidate data-busy-form>
            <?= csrf_field() ?>
            <fieldset>
                <legend>What this person can do</legend>
                <?php foreach ($permissions as $key => $label): ?>
                    <label class="check"><input type="checkbox" name="permissions[]" value="<?= e($key) ?>" <?= in_array($key, $granted, true) ? 'checked' : '' ?>> <?= e($label) ?></label>
                <?php endforeach; ?>
            </fieldset>
            <div class="msg-row"><a href="<?= e(url('/admin/staff')) ?>">Cancel</a><button class="btn">Save permissions</button></div>
        </form>
    </section>
</div>
