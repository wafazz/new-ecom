<?php
/** Expects $counts (active, pending, unsubscribed) and $rows (confirmed addresses). */
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Newsletter</h1>
            <p class="muted">Only confirmed addresses are listed. Addresses still waiting to confirm, and people who unsubscribed, are not included.</p>
        </div>
        <a class="btn" href="<?= e(url('/admin/newsletter/export')) ?>">Download CSV</a>
    </header>

    <section class="msg-card">
        <h2><?= (int) $counts['active'] ?> confirmed</h2>
        <p class="muted"><?= (int) $counts['pending'] ?> waiting to confirm · <?= (int) $counts['unsubscribed'] ?> unsubscribed</p>
        <?php if ($rows === []): ?>
            <p class="muted">No one has confirmed a sign-up yet.</p>
        <?php else: ?>
            <div class="msg-table-wrap">
                <table class="msg-table">
                    <thead><tr><th>Email</th><th>Confirmed</th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr><td><?= e($r['email']) ?></td><td><?= e((string) $r['confirmed_at']) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
