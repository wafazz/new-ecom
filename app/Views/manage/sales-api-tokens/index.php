<?php
/** Expects $tokens, $endpoint, and optionally $newToken / $newLabel (shown once, right after creation). */
$when = fn(?string $t) => $t ? date('d M Y, H:i', strtotime($t)) : '—';
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Sales API tokens</h1>
            <p class="muted">Lets the CodexLure store-sales dashboard read this shop's sales totals. Tokens are read-only and can be revoked at any time. This page is not in the menu; open it from the address bar.</p>
        </div>
    </header>

    <?php if (!empty($newToken)): ?>
    <section class="msg-card" role="status">
        <h2>New token for "<?= e((string) ($newLabel ?? '')) ?>"</h2>
        <p class="muted">Copy it now. It is shown only once and cannot be recovered later.</p>
        <div class="row-between" style="margin-top:.6rem;flex-wrap:wrap;gap:.5rem">
            <code id="new-token" style="word-break:break-all"><?= e($newToken) ?></code>
            <button class="btn" type="button" data-copy="<?= e($newToken) ?>">Copy token</button>
        </div>
        <p class="muted" style="margin-top:1rem">Endpoint: <code><?= e($endpoint) ?></code>
            <button class="btn secondary" type="button" data-copy="<?= e($endpoint) ?>">Copy URL</button></p>
    </section>
    <?php endif; ?>

    <section class="msg-card">
        <h2>Create a token</h2>
        <form method="post" action="<?= e(url('/admin/sales-api-tokens')) ?>" style="margin-top:.6rem;display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end">
            <?= csrf_field() ?>
            <label style="display:grid;gap:.25rem;font-weight:600;font-size:.85rem">Label
                <input name="label" maxlength="100" placeholder="CodexLure dashboard" value="">
            </label>
            <button class="btn" type="submit">Generate token</button>
        </form>
    </section>

    <section class="msg-card">
        <h2>All tokens</h2>
        <?php if ($tokens === []): ?>
            <p class="msg-empty" style="margin-top:.6rem">No tokens yet.</p>
        <?php else: ?>
            <div class="table-wrap" style="margin-top:.6rem">
            <table class="msg-table">
                <thead><tr><th>Label</th><th>Token</th><th>Created</th><th>Last used</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($tokens as $t): $active = $t['revoked_at'] === null; ?>
                    <tr>
                        <td><strong><?= e($t['label']) ?></strong></td>
                        <td>…<?= e($t['token_last4']) ?></td>
                        <td><small><?= e($when($t['created_at'])) ?></small></td>
                        <td><small><?= e($when($t['last_used_at'])) ?></small></td>
                        <td><span class="msg-pill <?= $active ? 'ok' : 'neu' ?>"><?= $active ? 'Active' : 'Revoked' ?></span></td>
                        <td class="num">
                            <?php if ($active): ?>
                            <form method="post" action="<?= e(url('/admin/sales-api-tokens/' . (int) $t['id'] . '/revoke')) ?>" class="inline" data-swal-confirm="Revoke this token?" data-swal-text="Any dashboard using it will stop working." data-swal-danger="1" data-swal-button="Yes, revoke">
                                <?= csrf_field() ?><button class="btn secondary" type="submit">Revoke</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>
</div>
