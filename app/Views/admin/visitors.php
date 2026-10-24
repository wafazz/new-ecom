<?php
/** Expects $stats (see VisitorService::stats) and $online (minutes that count as online). */
$cards = [
    'today' => ['Today', 'unique visitors since midnight'],
    'week' => ['Last 7 days', 'unique visitors'],
    'month' => ['Last 30 days', 'unique visitors'],
    'overall' => ['All time', 'unique visitors ever'],
];
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Visitors</h1>
            <p class="muted">Unique browsers that opened the shop. Updates by itself every few seconds. Admin and staff pages are not counted.</p>
        </div>
        <span class="msg-pill ok" aria-live="polite">Live</span>
    </header>

    <div class="visitors" data-visitors-root data-stats-url="<?= e(url('/admin/visitors/stats')) ?>">
        <section class="msg-card visitors-online" aria-live="polite">
            <div class="visitors-online-num"><span data-stat="online"><?= (int) $stats['online'] ?></span></div>
            <div>
                <strong>Online now</strong>
                <div class="muted">seen in the last <?= (int) $online ?> minutes ·
                    <span data-stat="online_members"><?= (int) $stats['online_members'] ?></span> signed in ·
                    <span data-stat="online_guests"><?= (int) $stats['online_guests'] ?></span> guests</div>
            </div>
        </section>

        <div class="msg-grid" style="margin-top:1rem">
            <?php foreach ($cards as $key => [$label, $sub]): ?>
                <section class="msg-card visitors-card">
                    <div class="muted"><?= e($label) ?></div>
                    <div class="visitors-num" data-stat="<?= e($key) ?>"><?= (int) $stats[$key] ?></div>
                    <small class="muted"><?= e($sub) ?></small>
                </section>
            <?php endforeach; ?>
        </div>
        <p class="muted"><small>Last updated <span data-stat="at"><?= e($stats['at']) ?></span></small></p>
    </div>
</div>
