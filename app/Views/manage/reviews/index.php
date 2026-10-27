<?php
/** Expects $base ('/admin' or '/staff') and $reviews (ReviewService::all()). */
$hidden = count(array_filter($reviews, fn($r) => $r['status'] === 'hidden'));
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Reviews</h1>
            <p class="muted">Reviews go live as soon as a verified buyer posts them. Hide one to take it off the shop without deleting it, or delete it for good.</p>
        </div>
        <div class="row-between">
            <span class="msg-pill ok"><?= count($reviews) ?> review<?= count($reviews) === 1 ? '' : 's' ?></span>
            <?php if ($hidden > 0): ?><span class="msg-pill warn"><?= $hidden ?> hidden</span><?php endif; ?>
        </div>
    </header>

    <section class="msg-card">
        <?php if ($reviews === []): ?>
            <p class="msg-empty" style="margin-top:.6rem">No reviews yet. They appear here once a customer with a completed order posts one.</p>
        <?php else: ?>
            <div class="table-wrap" style="margin-top:.6rem">
            <table class="msg-table">
                <thead><tr><th>Product</th><th>Reviewer</th><th>Rating</th><th>Review</th><th>Date</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($reviews as $r): ?>
                    <tr>
                        <td><strong><?= e($r['product_name']) ?></strong></td>
                        <td><?= e($r['reviewer']) ?><?php if (!empty($r['verified'])): ?><br><small class="muted">Verified buyer</small><?php endif; ?></td>
                        <td><span class="rv-stars" aria-label="<?= (int) $r['rating'] ?> out of 5"><?= e(str_repeat('★', (int) $r['rating']) . str_repeat('☆', 5 - (int) $r['rating'])) ?></span></td>
                        <td style="max-width:320px"><?php if ($r['title']): ?><strong><?= e($r['title']) ?></strong><br><?php endif; ?><small><?= e(mb_strimwidth((string) $r['body'], 0, 180, '…')) ?></small></td>
                        <td><small><?= e(date('d M Y', strtotime((string) $r['created_at']))) ?></small></td>
                        <td><span class="msg-pill <?= $r['status'] === 'approved' ? 'ok' : 'neu' ?>"><?= $r['status'] === 'approved' ? 'Shown' : 'Hidden' ?></span></td>
                        <td class="num">
                            <form method="post" action="<?= e(url($base . '/reviews/' . (int) $r['id'] . '/status')) ?>" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="status" value="<?= $r['status'] === 'approved' ? 'hidden' : 'approved' ?>">
                                <button class="btn secondary" type="submit"><?= $r['status'] === 'approved' ? 'Hide' : 'Show' ?></button>
                            </form>
                            <form method="post" action="<?= e(url($base . '/reviews/' . (int) $r['id'] . '/delete')) ?>" class="inline" data-swal-confirm="Delete this review?" data-swal-text="This cannot be undone." data-swal-danger="1" data-swal-button="Yes, delete">
                                <?= csrf_field() ?><button class="btn secondary" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>
</div>
