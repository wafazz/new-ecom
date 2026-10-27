<?php
/** Product reviews block. Expects $product, $reviews, $reviewSummary, $reviewState (null = guest, else reviewed | eligible | not_buyer). */
$stars = function (float $n): string {
    $full = max(0, min(5, (int) round($n)));
    return str_repeat('★', $full) . str_repeat('☆', 5 - $full);
};
$count = (int) $reviewSummary['count'];
$avg = $reviewSummary['avg'];
?>
<section class="pdp-reviews" id="reviews" aria-labelledby="reviews-title">
    <div class="sec-head">
        <div>
            <h2 id="reviews-title">Customer reviews</h2>
            <?php if ($count > 0): ?>
                <p class="muted rv-summary"><span class="rv-stars" aria-label="<?= e(number_format((float) $avg, 1)) ?> out of 5 stars"><?= e($stars((float) $avg)) ?></span>
                    <?= e(number_format((float) $avg, 1)) ?> out of 5 · <?= $count ?> review<?= $count === 1 ? '' : 's' ?></p>
            <?php else: ?>
                <p class="muted">No reviews yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($reviewState === 'eligible'): ?>
        <form method="post" action="<?= e(url('/products/' . $product['slug'] . '/reviews')) ?>" class="rv-form" novalidate>
            <?= csrf_field() ?>
            <fieldset class="rv-rating">
                <legend>Your rating</legend>
                <div class="rv-stars-input">
                    <?php for ($n = 5; $n >= 1; $n--): ?>
                        <input type="radio" id="rv-<?= $n ?>" name="rating" value="<?= $n ?>" required>
                        <label for="rv-<?= $n ?>" aria-label="<?= $n ?> star<?= $n === 1 ? '' : 's' ?>">★</label>
                    <?php endfor; ?>
                </div>
                <?= field_error('rating') ?>
            </fieldset>
            <label>Title (optional)
                <input name="title" maxlength="120" value="<?= e(old('title')) ?>">
                <?= field_error('title') ?>
            </label>
            <label>Your review
                <textarea name="body" rows="4" maxlength="1000" required placeholder="What did you like, and what would you tell a friend?"><?= e(old('body')) ?></textarea>
                <?= field_error('body') ?>
            </label>
            <?= field_error('form') ?>
            <button class="s-btn" type="submit">Post review</button>
        </form>
    <?php elseif ($reviewState === 'reviewed'): ?>
        <p class="muted">Thanks, you have already reviewed this product.</p>
    <?php elseif ($reviewState === 'not_buyer'): ?>
        <p class="muted">Reviews are open to customers whose order for this product has been completed.</p>
    <?php elseif ($reviewState === null): ?>
        <p class="muted"><a href="<?= e(url('/login')) ?>">Log in</a> to review a product you have bought.</p>
    <?php endif; ?>

    <?php foreach ($reviews as $rv): ?>
        <article class="rv">
            <div class="rv-head">
                <span class="rv-stars" aria-label="<?= (int) $rv['rating'] ?> out of 5 stars"><?= e($stars((float) $rv['rating'])) ?></span>
                <?php if ($rv['title'] !== null && $rv['title'] !== ''): ?><strong><?= e($rv['title']) ?></strong><?php endif; ?>
            </div>
            <p class="rv-body"><?= nl2br(e((string) $rv['body'])) ?></p>
            <p class="rv-meta muted">
                <?= e($rv['reviewer']) ?> · <?= e(date('d M Y', strtotime((string) $rv['created_at']))) ?>
                <?php if (!empty($rv['verified'])): ?> · <span class="rv-verified">Verified buyer</span><?php endif; ?>
            </p>
        </article>
    <?php endforeach; ?>
</section>
