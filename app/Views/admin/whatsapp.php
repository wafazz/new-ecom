<?php
/** Expects $overview, $statuses, $variables, $defaults, $log. */
$state = $overview['enabled'] && $overview['token_set'] ? ['ok', 'Connected and sending']
    : ($overview['enabled'] ? ['warn', 'On, but the API token is missing'] : ['', 'Off']);
?>
<div class="msg-page" data-shop="<?= e((string) config('app.name')) ?>">
    <header class="msg-head">
        <div>
            <h1>WhatsApp</h1>
            <p class="muted">Each order status is sent to the customer on WhatsApp through onsend.io. A failed message never stops an order from changing.</p>
        </div>
        <span class="msg-pill <?= e($state[0]) ?>"><?= e($state[1]) ?></span>
    </header>

    <form method="post" action="<?= e(url('/admin/whatsapp')) ?>" id="wa-form" data-msg-form data-busy-form>
        <?= csrf_field() ?>
        <section class="msg-card msg-section">
            <div class="msg-row">
                <div>
                    <h2>Connection</h2>
                    <p class="muted">Turn sending on or off for the whole shop.</p>
                </div>
                <label class="msg-switch" data-switch-label>
                    <input type="checkbox" name="enabled" value="1" <?= $overview['enabled'] ? 'checked' : '' ?>>
                    <span class="track" aria-hidden="true"></span>
                    <span>Send WhatsApp messages</span>
                </label>
            </div>
            <div class="msg-field">
                <label for="wa-token">API token (onsend.io → Devices → View → Token)</label>
                <div class="msg-input-row">
                    <input id="wa-token" type="password" name="token" autocomplete="off"
                           placeholder="<?= $overview['token_set'] ? 'Saved (ends with ' . e((string) $overview['token_hint']) . '). Leave blank to keep it.' : 'Paste the token here' ?>">
                    <button type="button" class="msg-link" data-toggle-secret="wa-token" aria-pressed="false">Show</button>
                </div>
                <small class="muted">Stored encrypted. It is never shown again.</small>
            </div>
        </section>

        <div class="msg-head" style="margin-top:1.2rem">
            <div>
                <h2 style="margin:0">Message for each status</h2>
                <p class="muted">Click a placeholder to insert it. The preview uses sample details.</p>
            </div>
        </div>

        <div class="msg-grid" style="margin-top:.8rem">
            <?php foreach ($statuses as $key => $label): $t = $overview['templates'][$key]; $id = 'wa-' . $key; ?>
                <section class="msg-card">
                    <div class="msg-row">
                        <h2><?= e($label) ?></h2>
                        <label class="msg-switch">
                            <input type="checkbox" name="tpl[<?= e($key) ?>][on]" value="1" <?= $t['on'] ? 'checked' : '' ?>>
                            <span class="track" aria-hidden="true"></span>
                            <span>On</span>
                        </label>
                    </div>
                    <div class="msg-field">
                        <label for="<?= e($id) ?>">Message</label>
                        <textarea id="<?= e($id) ?>" name="tpl[<?= e($key) ?>][text]" rows="4" aria-label="<?= e($label) ?> message"><?= e($t['text']) ?></textarea>
                        <div class="msg-chips">
                            <?php foreach ($variables as $v): ?>
                                <button type="button" class="msg-chip" data-insert="<?= e($v) ?>"><?= e($v) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="msg-preview is-wa" data-preview-for="<?= e($id) ?>" data-preview-kind="wa">
                        <div class="msg-preview-label"><span>Preview</span><span data-count></span></div>
                        <div class="msg-preview-body"></div>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <div class="msg-savebar" data-savebar-for="wa-form">
            <span>You have unsaved changes</span>
            <button class="btn">Save WhatsApp settings</button>
        </div>
    </form>

    <div class="msg-grid">
        <section class="msg-card">
            <h2>Check the connection</h2>
            <p class="muted">Asks onsend.io whether the device is connected.</p>
            <form method="post" action="<?= e(url('/admin/whatsapp/check')) ?>" data-busy-form style="margin-top:.7rem">
                <?= csrf_field() ?>
                <button class="btn secondary" <?= $overview['token_set'] ? '' : 'disabled' ?>>Check device</button>
            </form>
        </section>
        <section class="msg-card">
            <h2>Send a test message</h2>
            <p class="muted">Sends one message to a number you choose.</p>
            <form method="post" action="<?= e(url('/admin/whatsapp/test')) ?>" data-busy-form class="msg-field">
                <?= csrf_field() ?>
                <div class="msg-input-row">
                    <input name="phone" inputmode="tel" placeholder="012-3456789" required aria-label="Phone number for the test">
                    <button class="btn secondary" <?= $overview['token_set'] ? '' : 'disabled' ?>>Send test</button>
                </div>
            </form>
        </section>
    </div>

    <section class="msg-card">
        <div class="msg-row">
            <h2>Recent messages</h2>
            <div class="msg-tabs" data-log-filter="wa" role="group" aria-label="Filter messages">
                <button type="button" data-filter="all" class="is-on" aria-pressed="true">All</button>
                <button type="button" data-filter="sent" aria-pressed="false">Sent</button>
                <button type="button" data-filter="failed" aria-pressed="false">Failed</button>
            </div>
        </div>
        <?php if ($log === []): ?>
            <p class="msg-empty" style="margin-top:.8rem">No messages sent yet. They appear here as orders change.</p>
        <?php else: ?>
            <div class="table-wrap" style="margin-top:.6rem">
            <table class="msg-table" data-log-table="wa">
                <thead><tr><th>When</th><th>Order</th><th>Status</th><th>To</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach ($log as $r): ?>
                    <tr data-ok="<?= $r['ok'] ? '1' : '0' ?>">
                        <td><?= e($r['created_at']) ?></td>
                        <td><?= $r['order_no'] ? e($r['order_no']) : '<span class="muted">test</span>' ?></td>
                        <td><?= e($statuses[$r['status_key']] ?? $r['status_key']) ?></td>
                        <td><?= e((string) $r['phone']) ?></td>
                        <td><?= $r['ok'] ? '<span class="msg-status ok">Sent</span>' : '<span class="msg-status bad">' . e((string) $r['error']) . '</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>
</div>
