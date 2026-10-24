<?php
/** Expects $filters, $kinds, $result (rows, total, page, pages). */
$qs = fn(array $extra) => url('/admin/email/log') . '?' . http_build_query(array_filter(array_merge($filters, $extra), fn($v) => $v !== '' && $v !== null));
$first = $result['total'] === 0 ? 0 : (($result['page'] - 1) * \App\Services\EmailLog::PER_PAGE) + 1;
$last = min($result['total'], $result['page'] * \App\Services\EmailLog::PER_PAGE);
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Email log</h1>
            <p class="muted">Every email the shop has sent: sign-up confirmations, password resets, order emails, announcements, voucher codes and tests. Newest first.</p>
        </div>
        <span class="msg-pill ok"><?= (int) $result['total'] ?> email<?= $result['total'] === 1 ? '' : 's' ?></span>
    </header>

    <section class="msg-card">
        <form method="get" action="<?= e(url('/admin/email/log')) ?>" class="grid-form">
            <label>Type
                <select name="kind">
                    <option value="">All types</option>
                    <?php foreach ($kinds as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $filters['kind'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Result
                <select name="status">
                    <option value="">Sent and failed</option>
                    <option value="sent" <?= $filters['status'] === 'sent' ? 'selected' : '' ?>>Sent</option>
                    <option value="failed" <?= $filters['status'] === 'failed' ? 'selected' : '' ?>>Failed</option>
                </select>
            </label>
            <label>Address or subject
                <input name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="name@example.com">
            </label>
            <label>From
                <input type="date" name="from" value="<?= e($filters['from']) ?>">
            </label>
            <label>To
                <input type="date" name="to" value="<?= e($filters['to']) ?>">
            </label>
            <div class="row-between">
                <a class="btn secondary" href="<?= e(url('/admin/email/log')) ?>">Clear filters</a>
                <button class="btn">Filter</button>
            </div>
        </form>
    </section>

    <section class="msg-card">
        <p class="muted" style="margin:0 0 .6rem">Showing <?= $first ?>–<?= $last ?> of <?= (int) $result['total'] ?>.</p>
        <?php if ($result['rows'] === []): ?>
            <p class="msg-empty">No emails match these filters.</p>
        <?php else: ?>
            <div class="table-wrap">
            <table class="msg-table">
                <thead><tr><th>When</th><th>Type</th><th>To</th><th>Subject</th><th>Order</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $r): ?>
                    <tr>
                        <td><small><?= e((string) $r['created_at']) ?></small></td>
                        <td><?= e(\App\Services\EmailLog::KINDS[$r['kind']] ?? $r['kind']) ?></td>
                        <td><?= e((string) $r['to_email']) ?></td>
                        <td><?= e((string) $r['subject']) ?></td>
                        <td><?= $r['order_id'] ? '#' . (int) $r['order_id'] : '<span class="muted">–</span>' ?></td>
                        <td><?= (int) $r['ok'] === 1 ? '<span class="msg-status ok">Sent</span>' : '<span class="msg-status bad">' . e((string) $r['error']) . '</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>

        <?php if ($result['pages'] > 1): ?>
            <nav class="msg-row" aria-label="Pages" style="margin-top:.8rem">
                <?php if ($result['page'] > 1): ?>
                    <a class="btn secondary" href="<?= e($qs(['page' => $result['page'] - 1])) ?>">‹ Newer</a>
                <?php else: ?><span></span><?php endif; ?>
                <span class="muted">Page <?= (int) $result['page'] ?> of <?= (int) $result['pages'] ?></span>
                <?php if ($result['page'] < $result['pages']): ?>
                    <a class="btn secondary" href="<?= e($qs(['page' => $result['page'] + 1])) ?>">Older ›</a>
                <?php else: ?><span></span><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</div>
