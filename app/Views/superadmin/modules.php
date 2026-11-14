<?php
/** Expects $modules (Modules::all()) and $audit (AuditService::recent('module.')). */
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Modules</h1>
            <p class="muted">Switch whole features on or off for the shop. Only superadmins can see this page. Admins run the back office but cannot change these switches.</p>
        </div>
    </header>

    <section class="msg-card">
        <h2>Feature switches</h2>
        <?php foreach ($modules as $m): ?>
            <form method="post" action="<?= e(url('/superadmin/modules/' . $m['key'])) ?>" class="module-row" data-busy-form>
                <?= csrf_field() ?>
                <div>
                    <strong><?= e($m['label']) ?></strong>
                    <span class="pill"><?= $m['enabled'] ? 'On' : 'Off' ?></span>
                    <p class="muted"><?= e($m['help']) ?></p>
                </div>
                <input type="hidden" name="enabled" value="<?= $m['enabled'] ? '0' : '1' ?>">
                <button class="btn" type="submit"><?= $m['enabled'] ? 'Switch off' : 'Switch on' ?></button>
            </form>
        <?php endforeach; ?>
    </section>

    <section class="msg-card">
        <h2>Recent changes</h2>
        <?php if ($audit === []): ?>
            <p class="muted">No module changes yet.</p>
        <?php else: ?>
            <ul class="perm-chips">
                <?php foreach ($audit as $a): ?>
                    <li><?= e($a['created_at']) ?> · <?= e($a['actor'] ?? 'unknown') ?> · <?= e($a['action']) ?> <?= e((string) $a['detail']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
