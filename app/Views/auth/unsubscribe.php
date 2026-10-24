<?php
/** Expects $state ('ask'|'done'|'invalid'), $email, $token. */
?>
<?php if ($state === 'invalid'): ?>
    <h1>Link not valid</h1>
    <p class="muted">This unsubscribe link is not valid. If you still get emails you do not want, reply to one of them and ask to be removed.</p>
<?php elseif ($state === 'done'): ?>
    <h1>You are unsubscribed</h1>
    <p class="muted"><?= e($email) ?> will no longer get announcement emails. Order and account emails still arrive.</p>
<?php else: ?>
    <h1>Stop announcement emails?</h1>
    <p class="muted"><?= e($email) ?> will no longer get announcement emails from this shop. Order and account emails still arrive.</p>
    <form method="post" action="<?= e(url('/unsubscribe/' . $token)) ?>" novalidate>
        <button class="s-btn lg block">Unsubscribe</button>
    </form>
<?php endif; ?>
