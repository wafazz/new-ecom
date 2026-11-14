<?php
/** Expects $order and $supportAction (path|null). The message thread about this order, and a box to write in. */
use App\Services\Modules;
use App\Services\SupportService;

if (!Modules::enabled('support') || empty($supportAction)) {
    return;
}
$thread = SupportService::thread((int) $order['id']);
?>
<section class="support-thread" aria-labelledby="support-title">
    <h2 id="support-title">Messages about this order</h2>
    <?php if ($thread === []): ?>
        <p class="muted">Ask us anything about this order. We reply here.</p>
    <?php else: ?>
        <ol class="support-list">
            <?php foreach ($thread as $m): ?>
                <li class="support-msg <?= $m['sender'] === 'staff' ? 'is-staff' : 'is-customer' ?>">
                    <strong><?= $m['sender'] === 'staff' ? 'The shop' : 'You' ?></strong>
                    <small class="muted"><?= e(date('d M Y, H:i', strtotime((string) $m['created_at']))) ?></small>
                    <p><?= nl2br(e($m['body'])) ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
    <form method="post" action="<?= e(url($supportAction)) ?>" class="card-form" data-busy-form>
        <?= csrf_field() ?>
        <label>Your message
            <textarea name="body" rows="3" required maxlength="2000"></textarea></label>
        <button class="btn secondary" type="submit">Send message</button>
    </form>
</section>
