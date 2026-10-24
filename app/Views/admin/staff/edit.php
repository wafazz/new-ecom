<p><a href="<?= e(url('/admin/staff')) ?>">← Staff</a></p>
<h1>Permissions <small class="muted"><?= e($staff['name']) ?> · <?= e($staff['email']) ?></small></h1>
<form method="post" action="<?= e(url('/admin/staff/' . (int) $staff['id'] . '/permissions')) ?>" class="card-form" novalidate>
    <?= csrf_field() ?>
    <fieldset>
        <legend>What this person can do</legend>
        <?php foreach ($permissions as $key => $label): ?>
            <label class="check"><input type="checkbox" name="permissions[]" value="<?= e($key) ?>" <?= in_array($key, $granted, true) ? 'checked' : '' ?>> <?= e($label) ?></label>
        <?php endforeach; ?>
    </fieldset>
    <button class="btn">Save permissions</button>
    <a href="<?= e(url('/admin/staff')) ?>">Cancel</a>
</form>
