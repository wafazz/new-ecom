<div class="dash" id="dash" data-mode="staff" data-endpoint="<?= e(url('/staff/dashboard/data')) ?>">
    <script type="application/json" id="dash-data"><?= json_encode($dash, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?></script>
    <header class="dash-head">
        <div><h1>Dashboard</h1><p class="muted" id="dash-sub">&nbsp;</p></div>
        <div class="dash-controls">
            <button type="button" class="icon-btn" id="dash-refresh" title="Refresh now" aria-label="Refresh now">
                <svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 10-2.3 5.7M20 4v7h-7"/></svg>
            </button>
        </div>
    </header>

    <?php if ($perms === []): ?>
        <section class="panel"><h2>No access assigned yet</h2><p class="muted">An admin needs to give you permissions before there is anything to work on here.</p></section>
    <?php else: ?>
    <div class="dash-cols">
        <aside class="dash-side">
            <section class="panel" aria-labelledby="q-title"><h2 id="q-title">Needs attention</h2><div id="dash-queue" class="queue"></div></section>
            <section class="panel"><h2>Your access</h2>
                <ul class="chips"><?php foreach ($perms as $p): ?><li><?= e($labels[$p] ?? $p) ?></li><?php endforeach; ?></ul>
            </section>
        </aside>
        <div class="dash-main">
            <?php if (in_array('orders.view', $perms, true)): ?>
            <div class="dash-two">
                <section class="panel" aria-labelledby="bd-title"><h2 id="bd-title">Orders · last 30 days</h2><div id="dash-bars"></div></section>
                <?php if (in_array('stock.manage', $perms, true)): ?><section class="panel"><h2>Low stock</h2><div id="dash-stock"></div></section><?php endif; ?>
            </div>
            <section class="panel" aria-labelledby="rc-title">
                <div class="panel-head"><h2 id="rc-title">Latest orders</h2><a href="<?= e(url('/staff/orders')) ?>">All orders →</a></div>
                <div id="dash-recent" class="table-wrap"></div>
            </section>
            <?php elseif (in_array('stock.manage', $perms, true)): ?>
            <section class="panel"><h2>Low stock</h2><div id="dash-stock"></div></section>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
