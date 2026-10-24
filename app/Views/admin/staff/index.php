<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Staff</h1>
            <p class="muted">People who sign in to the admin area with only the permissions you give them.</p>
        </div>
        <div class="msg-row">
            <span class="msg-pill"><?= count($staff) ?> staff</span>
            <a class="btn" href="<?= e(url('/admin/staff/create')) ?>">Add staff</a>
        </div>
    </header>

    <section class="msg-card">
        <?php if ($staff === []): ?>
            <p class="msg-empty">No staff yet. Add someone to give them access to part of the admin area.</p>
        <?php else: ?>
        <div class="table-wrap">
        <table class="msg-table">
            <thead><tr><th>Name</th><th>Email</th><th>Permissions</th><th>Status</th><th>Last login</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($staff as $s): $perms = json_decode((string) $s['permissions'], true) ?: []; ?>
                <tr>
                    <td><?= e($s['name']) ?></td>
                    <td><?= e($s['email']) ?></td>
                    <td><small><?= e(implode(', ', $perms) ?: '—') ?></small></td>
                    <td><span class="msg-pill <?= $s['status'] === 'active' ? 'ok' : 'bad' ?>"><?= e($s['status']) ?></span></td>
                    <td><small><?= e($s['last_login_at'] ?? 'never') ?></small></td>
                    <td class="num">
                        <a href="<?= e(url('/admin/staff/' . (int) $s['id'] . '/edit')) ?>">Permissions</a> ·
                        <form method="post" action="<?= e(url('/admin/staff/' . (int) $s['id'] . '/toggle')) ?>" class="inline">
                            <?= csrf_field() ?>
                            <button class="msg-link"><?= $s['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
</div>
