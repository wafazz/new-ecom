<?php
$me = \App\Core\Auth::user();
$t = $d['track'];
$hour = (int) date('G');
$when = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$first = explode(' ', trim((string) $me['name']))[0];
?>
<div class="dash" id="member-dash">
    <?php if (empty($me['email_verified_at']) && \App\Services\EmailService::ready()): ?>
    <section class="panel verify-banner" aria-labelledby="verify-title">
        <div>
            <strong id="verify-title">Confirm your email address</strong>
            <p class="muted">We sent a confirmation link to <?= e((string) $me['email']) ?>. Click it to confirm your address.</p>
        </div>
        <form method="post" action="<?= e(url('/member/verify-email/resend')) ?>" class="inline"><?= csrf_field() ?><button class="btn secondary">Send the link again</button></form>
    </section>
    <?php endif; ?>
    <header class="dash-head">
        <div><h1><?= e($when) ?>, <?= e($first) ?></h1>
            <p class="muted"><?= $d['orders'] > 0 ? 'Here is where your orders and rewards stand.' : 'Your account is ready. Your first order will show up here.' ?></p></div>
        <a class="btn" href="<?= e(url('/products')) ?>">Continue shopping</a>
    </header>

    <?php if ($t): $o = $t['order']; $cur = (int) $t['current']; ?>
    <section class="panel track" aria-labelledby="tr-title">
        <div class="panel-head">
            <h2 id="tr-title">Your order in progress · <?= e($o['order_no']) ?></h2>
            <a href="<?= e(url('/member/orders/' . $o['order_no'])) ?>">Order details →</a>
        </div>
        <ol class="stepper" style="--cur: <?= $cur ?>">
            <?php foreach ($t['steps'] as $i => $st): $state = $i < $cur ? 'done' : ($i === $cur ? 'now' : 'todo'); ?>
                <li class="<?= $state ?>">
                    <span class="dotp" aria-hidden="true"><?= $state === 'done' ? '✓' : $i + 1 ?></span>
                    <strong><?= e($st['label']) ?></strong>
                    <small class="muted"><?= $st['at'] ? e(date('j M, g:ia', $st['at'])) : ($state === 'now' ? 'in progress' : '') ?></small>
                </li>
            <?php endforeach; ?>
        </ol>
        <p class="muted track-note">
            <?php if ($t['shipment']): ?>NinjaVan tracking number <strong><?= e($t['shipment']['tracking_number']) ?></strong> · <?= e($t['shipment']['status']) ?>
            <?php elseif ($o['payment_status'] === 'unpaid' && $o['payment_method'] !== 'cod'): ?>Waiting for your payment. <a href="<?= e(url('/member/orders/' . $o['order_no'])) ?>">See how to pay</a>.
            <?php else: ?>We are getting it ready. You will see the courier tracking number here once it ships.<?php endif; ?>
        </p>
    </section>
    <?php endif; ?>

    <div class="tiles four">
        <div class="tile"><div class="tile-label">Orders</div><div class="tile-value" data-count="<?= (int) $d['orders'] ?>"><?= (int) $d['orders'] ?></div><a href="<?= e(url('/member/orders')) ?>" class="muted">View all</a></div>
        <div class="tile"><div class="tile-label">Spent (excluding cancelled)</div><div class="tile-value" data-count="<?= e((string) $d['spent']) ?>" data-format="money"><?= e(money($d['spent'])) ?></div></div>
        <div class="tile"><div class="tile-label">Wishlist</div><div class="tile-value" data-count="<?= (int) $d['wishlist'] ?>"><?= (int) $d['wishlist'] ?></div><a href="<?= e(url('/member/wishlist')) ?>" class="muted">Open</a></div>
        <div class="tile"><div class="tile-label">Saved addresses</div><div class="tile-value" data-count="<?= (int) $d['addresses'] ?>"><?= (int) $d['addresses'] ?></div><a href="<?= e(url('/member/addresses')) ?>" class="muted">Manage</a></div>
    </div>

    <div class="dash-two">
        <section class="panel" aria-labelledby="ro-title">
            <div class="panel-head"><h2 id="ro-title">Recent orders</h2><a href="<?= e(url('/member/orders')) ?>">All →</a></div>
            <?php if ($d['recent'] === []): ?>
                <p class="muted empty">No orders yet. <a href="<?= e(url('/products')) ?>">Browse the shop</a>.</p>
            <?php else: ?>
                <table class="dash-table">
                    <thead><tr><th>Order</th><th>Status</th><th class="num">Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($d['recent'] as $r): ?>
                        <tr class="clickable" onclick="location.href='<?= e(url('/member/orders/' . $r['order_no'])) ?>'">
                            <td><a href="<?= e(url('/member/orders/' . $r['order_no'])) ?>"><?= e($r['order_no']) ?></a><br><small class="muted"><?= e(date('j M Y', (int) strtotime($r['created_at']))) ?></small></td>
                            <td><span class="badge st-<?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
                            <td class="num"><?= e(money($r['total'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="af-title">
            <div class="panel-head"><h2 id="af-title">Affiliate</h2><a href="<?= e(url('/member/affiliate')) ?>">Details →</a></div>
            <?php if ($d['affiliate']): $a = $d['affiliate']; ?>
                <p class="muted">Clicks on your link, last 30 days</p>
                <div class="spark-lg" data-spark="<?= e(json_encode($a['series'])) ?>"></div>
                <div class="aff-stats">
                    <div><strong><?= (int) $a['stats']['clicks'] ?></strong><span>clicks</span></div>
                    <div><strong><?= (int) $a['stats']['orders'] ?></strong><span>orders</span></div>
                    <div><strong><?= e(money($a['stats']['pending'] + $a['stats']['approved'])) ?></strong><span>to be paid</span></div>
                    <div><strong><?= e(money($a['stats']['paid'])) ?></strong><span>paid out</span></div>
                </div>
                <div class="copy-row">
                    <input id="aff-link" readonly value="<?= e($a['link']) ?>" onfocus="this.select()" aria-label="Your affiliate link">
                    <button type="button" class="btn" data-copy="<?= e($a['link']) ?>" data-target="aff-link">Copy link</button>
                </div>
            <?php else: ?>
                <p class="muted">Earn commission when people buy through your link.</p>
                <p><a class="btn secondary" href="<?= e(url('/member/affiliate')) ?>">Get my link</a></p>
            <?php endif; ?>
        </section>
    </div>
</div>
