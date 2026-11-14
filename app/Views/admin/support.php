<?php
/** Expects $waiting: orders whose latest message is from the customer. */
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Order messages</h1>
            <p class="muted">Orders where the customer wrote last. Reply to clear them from this list.</p>
        </div>
    </header>
    <section class="msg-card">
        <?php if ($waiting === []): ?>
            <p class="muted">Nothing is waiting for a reply.</p>
        <?php else: ?>
            <div class="msg-table-wrap">
                <table class="msg-table">
                    <thead><tr><th>Order</th><th>Customer</th><th>Latest message</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($waiting as $w): ?>
                            <tr>
                                <td><?= e($w['order_no']) ?></td>
                                <td><?= e($w['customer_name']) ?></td>
                                <td><?= e(mb_strimwidth((string) $w['last_body'], 0, 120, '…')) ?><br><small class="muted"><?= e((string) $w['last_at']) ?></small></td>
                                <td><a class="btn sm" href="<?= e(url('/admin/support/' . (int) $w['id'])) ?>">Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
