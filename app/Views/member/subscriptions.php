<?php
/** Expects $subs and $cadences. */
?>
<section class="member-subs">
    <h1>Subscriptions</h1>
    <?php if ($subs === []): ?>
        <p class="muted">You have no subscriptions. Open a product and choose "Subscribe" to have it delivered regularly.</p>
    <?php else: ?>
        <div class="mod-grid">
            <?php foreach ($subs as $s): ?>
                <article class="mod-card">
                    <h2><?= e($s['name']) ?></h2>
                    <p class="muted"><?= e($cadences[$s['cadence']] ?? $s['cadence']) ?> · next: <?= e($s['status'] === 'cancelled' ? '—' : (string) $s['next_due']) ?></p>
                    <p><strong><?= e(ucfirst($s['status'])) ?></strong><?= $s['pause_reason'] ? ' · ' . e($s['pause_reason']) : '' ?></p>
                    <?php if ($s['status'] !== 'cancelled'): ?>
                        <div class="mod-actions">
                            <?php if ($s['status'] === 'active'): ?>
                                <form method="post" action="<?= e(url('/member/subscriptions/' . (int) $s['id'] . '/pause')) ?>"><?= csrf_field() ?><button class="btn secondary sm">Pause</button></form>
                            <?php elseif ($s['pause_reason'] === 'Paused by you'): ?>
                                <form method="post" action="<?= e(url('/member/subscriptions/' . (int) $s['id'] . '/resume')) ?>"><?= csrf_field() ?><button class="btn sm">Resume</button></form>
                            <?php endif; ?>
                            <form method="post" action="<?= e(url('/member/subscriptions/' . (int) $s['id'] . '/cancel')) ?>" data-swal-confirm="Cancel this subscription?" data-swal-text="No more orders will be made." data-swal-button="Cancel subscription" data-swal-danger><?= csrf_field() ?><button class="btn secondary sm">Cancel</button></form>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
