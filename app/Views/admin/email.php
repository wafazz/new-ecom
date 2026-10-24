<?php
/** Expects $overview, $variables, $defaults, $log. */
$kindLabels = ['verify' => ['Confirm email address', 'Sent when a member signs up.'], 'order' => ['Order created', 'Sent to the customer when an order is placed.'], 'reset' => ['Reset password', 'Sent when someone asks for a new password.']];
$state = $overview['enabled'] && $overview['key_set'] && $overview['from_email'] !== '' ? ['ok', 'Connected and sending']
    : ($overview['enabled'] ? ['warn', 'On, but the key or sender is missing'] : ['', 'Off']);
?>
<div class="msg-page" data-shop="<?= e((string) config('app.name')) ?>">
    <header class="msg-head">
        <div>
            <h1>Email</h1>
            <p class="muted">Sent through Resend (resend.com). The sender address must be on a domain you have verified in Resend.</p>
        </div>
        <span class="msg-pill <?= e($state[0]) ?>"><?= e($state[1]) ?></span>
    </header>

    <form method="post" action="<?= e(url('/admin/email')) ?>" id="em-form" data-msg-form data-busy-form>
        <?= csrf_field() ?>
        <section class="msg-card msg-section">
            <div class="msg-row">
                <div>
                    <h2>Connection and sender</h2>
                    <p class="muted">Turn email on or off for the whole shop.</p>
                </div>
                <label class="msg-switch">
                    <input type="checkbox" name="enabled" value="1" <?= $overview['enabled'] ? 'checked' : '' ?>>
                    <span class="track" aria-hidden="true"></span>
                    <span>Send email</span>
                </label>
            </div>
            <div class="msg-field">
                <label for="em-key">Resend API key</label>
                <div class="msg-input-row">
                    <input id="em-key" type="password" name="key" autocomplete="off" placeholder="<?= $overview['key_set'] ? 'Saved (ends with ' . e((string) $overview['key_hint']) . '). Leave blank to keep it.' : 're_…' ?>">
                    <button type="button" class="msg-link" data-toggle-secret="em-key" aria-pressed="false">Show</button>
                </div>
                <small class="muted">Stored encrypted. It is never shown again.</small>
            </div>
            <div class="msg-grid" style="gap:.8rem">
                <div class="msg-field"><label for="em-from-name">Sender name</label><input id="em-from-name" name="from_name" value="<?= e($overview['from_name']) ?>" maxlength="120"></div>
                <div class="msg-field"><label for="em-from-email">Sender email</label><input id="em-from-email" name="from_email" type="email" value="<?= e($overview['from_email']) ?>" placeholder="shop@yourdomain.com"></div>
            </div>
            <div class="msg-field"><label for="em-reply">Reply-to (optional)</label><input id="em-reply" name="reply_to" type="email" value="<?= e($overview['reply_to']) ?>"></div>
        </section>

        <div class="msg-head" style="margin-top:1.2rem">
            <div>
                <h2 style="margin:0">Automatic emails</h2>
                <p class="muted">Click a placeholder to insert it. The preview uses sample details.</p>
            </div>
        </div>

        <div class="msg-grid" style="margin-top:.8rem">
            <?php foreach ($kindLabels as $kind => [$label, $when]): $t = $overview['templates'][$kind]; ?>
                <section class="msg-card">
                    <div class="msg-row">
                        <div>
                            <h2><?= e($label) ?></h2>
                            <p class="muted"><?= e($when) ?></p>
                        </div>
                        <label class="msg-switch">
                            <input type="checkbox" name="tpl[<?= e($kind) ?>][on]" value="1" <?= $t['on'] ? 'checked' : '' ?>>
                            <span class="track" aria-hidden="true"></span>
                            <span>On</span>
                        </label>
                    </div>
                    <div class="msg-field">
                        <label for="em-<?= e($kind) ?>-subject">Subject</label>
                        <input id="em-<?= e($kind) ?>-subject" name="tpl[<?= e($kind) ?>][subject]" value="<?= e($t['subject']) ?>" maxlength="200">
                    </div>
                    <div class="msg-field">
                        <label for="em-<?= e($kind) ?>-body">Message</label>
                        <textarea id="em-<?= e($kind) ?>-body" name="tpl[<?= e($kind) ?>][body]" rows="7"><?= e($t['body']) ?></textarea>
                        <div class="msg-chips">
                            <?php foreach ($variables[$kind] as $v): ?>
                                <button type="button" class="msg-chip" data-insert="<?= e($v) ?>"><?= e($v) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="msg-preview" data-preview-for="em-<?= e($kind) ?>-body" data-preview-kind="text">
                        <div class="msg-preview-label"><span>Preview</span><span data-count></span></div>
                        <div class="msg-subject" data-subject-for="em-<?= e($kind) ?>-subject"></div>
                        <div class="msg-preview-body"></div>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <div class="msg-savebar" data-savebar-for="em-form">
            <span>You have unsaved changes</span>
            <button class="btn">Save email settings</button>
        </div>
    </form>

    <section class="msg-card" style="margin-top:1rem">
        <h2>Send a test</h2>
        <p class="muted">Sends one email to an address you choose, to check the key and the sender.</p>
        <form method="post" action="<?= e(url('/admin/email/test')) ?>" data-busy-form class="msg-field">
            <?= csrf_field() ?>
            <div class="msg-input-row">
                <input name="to" type="email" placeholder="you@example.com" required aria-label="Address for the test email">
                <button class="btn secondary">Send test email</button>
            </div>
        </form>
    </section>

    <section class="msg-card">
        <div class="msg-row">
            <h2>Recent emails</h2>
            <div class="msg-tabs" data-log-filter="em" role="group" aria-label="Filter emails">
                <button type="button" data-filter="all" class="is-on" aria-pressed="true">All</button>
                <button type="button" data-filter="sent" aria-pressed="false">Sent</button>
                <button type="button" data-filter="failed" aria-pressed="false">Failed</button>
            </div>
        </div>
        <?php if ($log === []): ?>
            <p class="msg-empty" style="margin-top:.8rem">No emails sent yet.</p>
        <?php else: ?>
            <div class="table-wrap" style="margin-top:.6rem">
            <table class="msg-table" data-log-table="em">
                <thead><tr><th>When</th><th>Type</th><th>To</th><th>Subject</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach ($log as $r): ?>
                    <tr data-ok="<?= $r['ok'] ? '1' : '0' ?>">
                        <td><?= e($r['created_at']) ?></td>
                        <td><?= e($r['kind']) ?></td>
                        <td><?= e($r['to_email']) ?></td>
                        <td><?= e((string) $r['subject']) ?></td>
                        <td><?= $r['ok'] ? '<span class="msg-status ok">Sent</span>' : '<span class="msg-status bad">' . e((string) $r['error']) . '</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>
</div>
