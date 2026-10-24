<div class="dash" id="dash" data-mode="admin" data-endpoint="<?= e(url('/admin/dashboard/data')) ?>">
    <script type="application/json" id="dash-data"><?= json_encode($dash, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?></script>

    <header class="dash-head">
        <div>
            <h1>Dashboard</h1>
            <p class="muted" id="dash-sub">&nbsp;</p>
        </div>
        <div class="dash-controls">
            <div class="seg" role="group" aria-label="Date range">
                <button type="button" data-range="7" aria-pressed="false">7 days</button>
                <button type="button" data-range="30" aria-pressed="true">30 days</button>
                <button type="button" data-range="90" aria-pressed="false">90 days</button>
            </div>
            <button type="button" class="icon-btn" id="dash-refresh" title="Refresh now" aria-label="Refresh now">
                <svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 10-2.3 5.7M20 4v7h-7"/></svg>
            </button>
        </div>
    </header>
    <noscript><p class="alert bad">The dashboard needs JavaScript. Use the menu to reach orders, products and the other pages.</p></noscript>

    <div class="dash-cols">
        <aside class="dash-side">
            <section class="panel" aria-labelledby="q-title">
                <h2 id="q-title">Needs attention</h2>
                <div id="dash-queue" class="queue"></div>
            </section>
            <section class="panel" id="dash-setup" hidden></section>
        </aside>

        <div class="dash-main">
            <section class="panel visitors-dash" aria-labelledby="vis-live-title" data-visitors-root data-stats-url="<?= e(url('/admin/visitors/stats')) ?>">
                <div class="row-between">
                    <h2 id="vis-live-title">Live visitors</h2>
                    <span class="vis-live-tag"><span class="live-dot" aria-hidden="true"></span> live</span>
                </div>

                <div class="vis-grid">
                    <a class="vis-card vis-hero" href="<?= e(url('/admin/visitors')) ?>" aria-label="Online now: open visitor details">
                        <span class="vis-label">Online now</span>
                        <span class="vis-big"><span data-stat="online" data-count><?= (int) $visitors['online'] ?></span></span>
                        <span class="vis-sub"><span data-stat="online_members"><?= (int) $visitors['online_members'] ?></span> signed in · <span data-stat="online_guests"><?= (int) $visitors['online_guests'] ?></span> guests</span>
                    </a>
                    <a class="vis-card" href="<?= e(url('/admin/visitors')) ?>">
                        <span class="vis-label">Today</span>
                        <span class="vis-num"><span data-stat="today" data-count><?= (int) $visitors['today'] ?></span></span>
                        <span class="vis-sub">unique visitors</span>
                    </a>
                    <a class="vis-card" href="<?= e(url('/admin/visitors')) ?>">
                        <span class="vis-label">Last 7 days</span>
                        <span class="vis-num"><span data-stat="week" data-count><?= (int) $visitors['week'] ?></span></span>
                        <span class="vis-sub">unique visitors</span>
                    </a>
                    <a class="vis-card" href="<?= e(url('/admin/visitors')) ?>">
                        <span class="vis-label">Last 30 days</span>
                        <span class="vis-num"><span data-stat="month" data-count><?= (int) $visitors['month'] ?></span></span>
                        <span class="vis-sub">unique visitors</span>
                    </a>
                    <a class="vis-card" href="<?= e(url('/admin/visitors')) ?>">
                        <span class="vis-label">All time</span>
                        <span class="vis-num"><span data-stat="overall" data-count><?= (int) $visitors['overall'] ?></span></span>
                        <span class="vis-sub">unique visitors</span>
                    </a>
                    <div class="vis-card vis-chart" aria-label="Unique visitors per day, last 7 days">
                        <span class="vis-label">Last 7 days, per day</span>
                        <div class="vis-bars" data-trend>
                            <?php $__max = max(1, ...array_column($visitors['trend'] ?? [], 'n')); foreach (($visitors['trend'] ?? []) as $__d): ?>
                                <span class="vis-bar-wrap" title="<?= e($__d['label']) ?>: <?= (int) $__d['n'] ?> unique visitors">
                                    <span class="vis-bar" style="height: <?= max(4, (int) round($__d['n'] / $__max * 100)) ?>%"></span>
                                    <span class="vis-bar-label"><?= e($__d['label']) ?></span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>

            <div class="kpis">
                <section class="hero" id="dash-hero" aria-label="Sales this period"></section>
                <div class="tiles" id="dash-tiles"></div>
            </div>

            <section class="panel" aria-labelledby="dash-chart-title">
                <div class="panel-head">
                    <h2 id="dash-chart-title">Sales per day</h2>
                    <div class="panel-tools">
                        <div class="seg" role="group" aria-label="Measure">
                            <button type="button" data-metric="sales" aria-pressed="true">Sales</button>
                            <button type="button" data-metric="orders" aria-pressed="false">Orders</button>
                            <button type="button" data-metric="profit" aria-pressed="false">Gross profit</button>
                        </div>
                        <button type="button" class="btn secondary sm" id="dash-table-toggle" aria-pressed="false">Show table</button>
                    </div>
                </div>
                <div class="legend" id="dash-legend"></div>
                <div class="chart-host" id="dash-chart"></div>
            </section>

            <div class="dash-two">
                <section class="panel" aria-labelledby="bd-title">
                    <div class="panel-head">
                        <h2 id="bd-title">Breakdown</h2>
                        <div class="seg" role="group" aria-label="Breakdown">
                            <button type="button" data-tab="status" aria-pressed="true">Status</button>
                            <button type="button" data-tab="products" aria-pressed="false">Top products</button>
                            <button type="button" data-tab="payments" aria-pressed="false">Payment</button>
                        </div>
                    </div>
                    <div id="dash-bars"></div>
                </section>
                <section class="panel" aria-labelledby="st-title">
                    <h2 id="st-title">Low stock</h2>
                    <div id="dash-stock"></div>
                </section>
            </div>

            <section class="panel" aria-labelledby="rc-title">
                <div class="panel-head"><h2 id="rc-title">Latest orders</h2><a href="<?= e(url('/admin/orders')) ?>">All orders →</a></div>
                <div id="dash-recent" class="table-wrap"></div>
            </section>
        </div>
    </div>
</div>
