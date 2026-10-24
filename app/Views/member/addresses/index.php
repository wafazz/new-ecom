<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Addresses</h1>
            <p class="muted">Saved delivery addresses. Pick one at checkout instead of typing it again.</p>
        </div>
        <span class="msg-pill"><?= count($addresses) ?> of <?= (int) $max ?> saved</span>
    </header>

    <?php if ($addresses === []): ?>
        <section class="msg-card"><p class="msg-empty">No saved addresses yet. Add one below, or tick "Save this address" at checkout.</p></section>
    <?php else: ?>
    <div class="msg-grid">
        <?php foreach ($addresses as $a): ?>
            <section class="msg-card">
                <div class="msg-row">
                    <strong><?= e($a['label'] ?: 'Address') ?></strong>
                    <?= $a['is_default'] ? '<span class="msg-pill ok">Default</span>' : '' ?>
                </div>
                <p style="margin:.6rem 0 0">
                    <?= e($a['name']) ?> · <?= e($a['phone']) ?><br>
                    <?= e($a['line1']) ?><?= $a['line2'] ? ', ' . e($a['line2']) : '' ?><br>
                    <?= e($a['postcode']) ?> <?= e($a['city']) ?>, <?= e($a['state']) ?>
                </p>
                <div class="msg-row" style="margin-top:.7rem">
                    <a href="<?= e(url('/member/addresses/' . (int) $a['id'] . '/edit')) ?>">Edit</a>
                    <span class="msg-row" style="justify-content:flex-end">
                        <?php if (!$a['is_default']): ?>
                            <form method="post" action="<?= e(url('/member/addresses/' . (int) $a['id'] . '/default')) ?>" class="inline" data-busy-form><?= csrf_field() ?><button class="msg-link">Make default</button></form>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('/member/addresses/' . (int) $a['id'] . '/delete')) ?>" class="inline" data-swal-confirm="Delete this address?" data-swal-text="Past orders keep their own copy of the address." data-swal-button="Yes, delete" data-swal-danger="1"><?= csrf_field() ?><button class="msg-link danger">Delete</button></form>
                    </span>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <section class="msg-card">
        <h2>Add an address</h2>
        <?php if ($full): ?>
            <p class="muted">You've reached the limit of <?= (int) $max ?> addresses. Delete one to add another.</p>
        <?php else:
            $action = '/member/addresses'; $a = []; $submit = 'Save address'; $withDefault = true;
            require BASE_PATH . '/app/Views/member/addresses/_form.php';
        endif; ?>
    </section>
</div>
