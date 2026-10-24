<?php
/** Expects $audiences, $counts (same order), $ready, $campaigns. */
$keys = array_keys($audiences);
$state = $ready ? ['ok', 'Ready to send'] : ['warn', 'Email is not set up yet'];
?>
<div class="msg-page" data-shop="<?= e((string) config('app.name')) ?>">
    <header class="msg-head">
        <div>
            <h1>Announcements</h1>
            <p class="muted">Send one email to members, to customers (including guests who ordered), or to everyone. Each person receives it once.</p>
        </div>
        <span class="msg-pill <?= e($state[0]) ?>"><?= e($state[1]) ?></span>
    </header>

    <?php if (!$ready): ?>
        <div class="msg-card" style="border-color:#fde68a;background:#fffbeb">
            Email is not switched on yet. Set it up on the <a href="<?= e(url('/admin/email')) ?>">Email page</a> first.
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/admin/email/announce')) ?>" id="an-form" novalidate data-busy-form
          data-swal-confirm="Send this announcement now?" data-swal-text="It goes to everyone in the chosen audience. This cannot be undone." data-swal-button="Yes, send it">
        <?= csrf_field() ?>
        <section class="msg-card msg-section">
            <h2>Who gets it</h2>
            <div class="aud-grid" role="radiogroup" aria-label="Audience">
                <?php foreach ($keys as $i => $k): ?>
                    <label class="aud-card <?= $i === 0 ? 'is-selected' : '' ?>">
                        <input type="radio" name="audience" value="<?= e($k) ?>" data-audience data-count="<?= (int) $counts[$i] ?>" <?= $i === 0 ? 'checked' : '' ?>>
                        <strong><?= e($audiences[$k]) ?></strong>
                        <span class="aud-count"><?= (int) $counts[$i] ?></span>
                        <span class="muted">people</span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="aud-summary" data-audience-summary aria-live="polite"></div>
        </section>

        <div class="msg-grid" style="margin-top:1rem">
            <section class="msg-card msg-section">
                <h2>Message</h2>
                <p class="muted"><small>Placeholders: {name} (their name, or "Pelanggan" if unknown) and {shop}.</small></p>
                <div class="msg-field">
                    <label for="an-subject">Subject</label>
                    <input id="an-subject" name="subject" maxlength="200" required>
                </div>
                <div class="msg-field">
                    <label for="an-body">Message</label>
                    <textarea id="an-body" name="body" rows="9" required></textarea>
                    <div class="msg-chips">
                        <button type="button" class="msg-chip" data-insert="{name}">{name}</button>
                        <button type="button" class="msg-chip" data-insert="{shop}">{shop}</button>
                    </div>
                </div>
            </section>
            <section class="msg-card">
                <h2>Preview</h2>
                <p class="muted">This is how the email looks to a customer (sample name).</p>
                <div class="msg-preview" data-preview-for="an-body" data-preview-kind="text" style="margin-top:.7rem">
                    <div class="msg-preview-label"><span>Email</span><span data-count></span></div>
                    <div class="msg-subject" data-subject-for="an-subject"></div>
                    <div class="msg-preview-body"></div>
                </div>
            </section>
        </div>

        <div style="margin-top:1rem"><button class="btn" <?= $ready ? '' : 'disabled' ?>>Send announcement</button></div>
    </form>

    <section class="msg-card" style="margin-top:1rem">
        <h2>Sent announcements</h2>
        <?php if ($campaigns === []): ?>
            <p class="msg-empty" style="margin-top:.6rem">Nothing sent yet.</p>
        <?php else: ?>
            <div class="table-wrap" style="margin-top:.6rem">
            <table class="msg-table">
                <thead><tr><th>When</th><th>Subject</th><th>Audience</th><th class="num">People</th><th class="num">Sent</th><th class="num">Failed</th></tr></thead>
                <tbody>
                <?php foreach ($campaigns as $c): ?>
                    <tr>
                        <td><?= e($c['created_at']) ?></td>
                        <td><?= e($c['subject']) ?></td>
                        <td><?= e($audiences[$c['audience']] ?? $c['audience']) ?></td>
                        <td class="num"><?= (int) $c['recipients'] ?></td>
                        <td class="num"><span class="msg-status ok"><?= (int) $c['sent'] ?></span></td>
                        <td class="num"><?= (int) $c['failed'] > 0 ? '<span class="msg-status bad">' . (int) $c['failed'] . '</span>' : '0' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>
</div>
