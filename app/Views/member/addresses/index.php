<h1>Addresses</h1>
<?php if ($addresses === []): ?>
    <p class="muted">No saved addresses yet. Add one below, or tick "Save this address" at checkout.</p>
<?php else: ?>
<div class="addr-list">
    <?php foreach ($addresses as $a): ?>
        <div class="addr">
            <strong><?= e($a['label'] ?: 'Address') ?></strong> <?= $a['is_default'] ? '<span class="badge active">Default</span>' : '' ?><br>
            <?= e($a['name']) ?> · <?= e($a['phone']) ?><br>
            <?= e($a['line1']) ?><?= $a['line2'] ? ', ' . e($a['line2']) : '' ?><br>
            <?= e($a['postcode']) ?> <?= e($a['city']) ?>, <?= e($a['state']) ?>
            <p>
                <a href="<?= e(url('/member/addresses/' . (int) $a['id'] . '/edit')) ?>">Edit</a>
                <?php if (!$a['is_default']): ?>
                    <form method="post" action="<?= e(url('/member/addresses/' . (int) $a['id'] . '/default')) ?>"><?= csrf_field() ?> · <button class="link">Make default</button></form>
                <?php endif; ?>
                <form method="post" action="<?= e(url('/member/addresses/' . (int) $a['id'] . '/delete')) ?>" data-swal-confirm="Delete this address?" data-swal-text="Past orders keep their own copy of the address." data-swal-button="Yes, delete" data-swal-danger="1"><?= csrf_field() ?> · <button class="link">Delete</button></form>
            </p>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<h2>Add an address</h2>
<?php if ($full): ?>
    <p class="muted">You've reached the limit of <?= (int) $max ?> addresses. Delete one to add another.</p>
<?php else:
    $action = '/member/addresses'; $a = []; $submit = 'Save address'; $withDefault = true;
    require BASE_PATH . '/app/Views/member/addresses/_form.php';
endif; ?>
