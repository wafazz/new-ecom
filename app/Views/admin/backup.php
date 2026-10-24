<?php
/** Expects $backups, $canZip, $compressed. */
$fmt = fn(int $b) => $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : number_format($b / 1024, 0) . ' KB';
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Backup</h1>
            <p class="muted">A copy of the shop database<?= $canZip ? ' and the uploaded images and receipts' : '' ?>. Keep copies somewhere safe, off the server. Backups hold customer details.</p>
        </div>
        <span class="msg-pill ok"><?= count($backups) ?> saved</span>
    </header>

    <section class="msg-card">
        <div class="msg-row">
            <div>
                <h2>Make a backup now</h2>
                <p class="muted"><?= $compressed ? 'The database is compressed.' : 'The database is saved uncompressed (zlib is not installed).' ?></p>
            </div>
            <form method="post" action="<?= e(url('/admin/backup')) ?>" data-busy-form class="msg-input-row">
                <?= csrf_field() ?>
                <?php if ($canZip): ?>
                    <label class="msg-switch"><input type="checkbox" name="with_files" value="1" checked><span class="track" aria-hidden="true"></span><span>Include images and receipts</span></label>
                <?php endif; ?>
                <button class="btn">Create backup</button>
            </form>
        </div>
    </section>

    <section class="msg-card">
        <h2>Saved backups</h2>
        <?php if ($backups === []): ?>
            <p class="msg-empty" style="margin-top:.6rem">No backups yet. Make one before any update or move to a new server.</p>
        <?php else: ?>
            <div class="table-wrap" style="margin-top:.6rem">
            <table class="msg-table">
                <thead><tr><th>Created</th><th>File</th><th class="num">Size</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($backups as $b): ?>
                    <tr>
                        <td><?= e(date('d M Y, H:i', $b['created'])) ?></td>
                        <td><code><?= e($b['name']) ?></code></td>
                        <td class="num"><?= e($fmt($b['size'])) ?></td>
                        <td class="num">
                            <a class="btn secondary" href="<?= e(url('/admin/backup/download/' . $b['name'])) ?>">Download</a>
                            <form method="post" action="<?= e(url('/admin/backup/' . $b['name'] . '/restore')) ?>" class="inline" data-busy-form>
                                <?= csrf_field() ?>
                                <input name="confirm" placeholder="Type RESTORE" autocomplete="off" required pattern="RESTORE" aria-label="Type RESTORE to confirm" style="width:9rem">
                                <button class="btn secondary" type="submit">Restore</button>
                            </form>
                            <form method="post" action="<?= e(url('/admin/backup/' . $b['name'] . '/delete')) ?>" class="inline" data-swal-confirm="Delete this backup?" data-swal-text="This cannot be undone." data-swal-danger="1" data-swal-button="Yes, delete">
                                <?= csrf_field() ?><button class="btn secondary" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="msg-card">
        <h2>How to restore</h2>
        <p class="muted">Restore a saved backup with its <strong>Restore</strong> button above. It replaces the current database, and a safety backup of the current database is saved first. Do this on a server with the same <code>.env</code> (especially <code>APP_KEY</code>, which the saved passwords and keys depend on). A large database may take a while; keep this page open until it finishes.</p>
        <p class="muted"><small>The button restores the database only. For the uploaded images and receipts, unzip the files backup into the project folder yourself.</small></p>
    </section>
</div>
