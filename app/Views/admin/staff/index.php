<div class="row-between"><h1>Staff</h1><a class="btn" href="<?= e(url('/admin/staff/create')) ?>">Add staff</a></div>
<?php if ($staff === []): ?>
    <p class="muted">No staff yet.</p>
<?php else: ?>
<table>
    <thead><tr><th>Name</th><th>Email</th><th>Permissions</th><th>Status</th><th>Last login</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($staff as $s): $perms = json_decode((string) $s['permissions'], true) ?: []; ?>
        <tr>
            <td><?= e($s['name']) ?></td>
            <td><?= e($s['email']) ?></td>
            <td><?= e(implode(', ', $perms) ?: '—') ?></td>
            <td><span class="badge <?= e($s['status']) ?>"><?= e($s['status']) ?></span></td>
            <td><?= e($s['last_login_at'] ?? 'never') ?></td>
            <td>
                <a href="<?= e(url('/admin/staff/' . (int) $s['id'] . '/edit')) ?>">Permissions</a> ·
                <form method="post" action="<?= e(url('/admin/staff/' . (int) $s['id'] . '/toggle')) ?>" class="inline">
                    <?= csrf_field() ?>
                    <button class="link"><?= $s['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
