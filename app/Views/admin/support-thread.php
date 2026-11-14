<?php
/** Expects $order (id, order_no, customer_name, customer_email, status) and $thread. */
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Order <?= e($order['order_no']) ?></h1>
            <p class="muted"><?= e($order['customer_name']) ?> · <?= e($order['customer_email']) ?> · order status: <?= e($order['status']) ?></p>
        </div>
        <a class="btn secondary" href="<?= e(url('/admin/orders/' . (int) $order['id'])) ?>">Open order</a>
    </header>
    <section class="msg-card">
        <?php if ($thread === []): ?>
            <p class="muted">No messages on this order yet.</p>
        <?php else: ?>
            <ol class="support-list">
                <?php foreach ($thread as $m): ?>
                    <li class="support-msg <?= $m['sender'] === 'staff' ? 'is-staff' : 'is-customer' ?>">
                        <strong><?= $m['sender'] === 'staff' ? 'Shop' . ($m['staff'] ? ' (' . e($m['staff']) . ')' : '') : 'Customer' ?></strong>
                        <small class="muted"><?= e((string) $m['created_at']) ?></small>
                        <p><?= nl2br(e($m['body'])) ?></p>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/admin/support/' . (int) $order['id'])) ?>" class="card-form">
            <?= csrf_field() ?>
            <label>Reply
                <textarea name="body" rows="4" required maxlength="2000"></textarea></label>
            <div class="msg-row"><span></span><button class="btn">Send reply</button></div>
        </form>
    </section>
</div>
