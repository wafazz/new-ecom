<?php
/** Expects $details, $fields, $logoSet. */
?>
<div class="msg-page">
    <header class="msg-head">
        <div>
            <h1>Invoice details</h1>
            <p class="muted">These details appear at the top and bottom of every PDF invoice. New invoices use them straight away; invoices already downloaded are not changed.</p>
        </div>
    </header>

    <section class="msg-card">
        <form method="post" action="<?= e(url('/admin/invoice')) ?>" novalidate class="grid-form">
            <?= csrf_field() ?>
            <label>Company name
                <input name="company" value="<?= e($details['company']) ?>" maxlength="500" required>
            </label>
            <label>Business registration no. (optional)
                <input name="reg_no" value="<?= e($details['reg_no']) ?>" maxlength="500" placeholder="202501012345 (1234567-X)">
            </label>
            <label>Address
                <textarea name="address" rows="3" maxlength="500" placeholder="No. 12, Jalan Kenari 5&#10;47100 Puchong, Selangor"><?= e($details['address']) ?></textarea>
            </label>
            <label>Phone
                <input name="phone" value="<?= e($details['phone']) ?>" maxlength="500">
            </label>
            <label>Email
                <input type="email" name="email" value="<?= e($details['email']) ?>" maxlength="500">
            </label>
            <label>Footer note
                <textarea name="footer" rows="2" maxlength="500" placeholder="Thank you for shopping with us."><?= e($details['footer']) ?></textarea>
            </label>
            <div class="row-between">
                <span class="muted"><?= $logoSet ? 'The shop logo is shown on invoices. Change it in Settings.' : 'Add a shop logo in Settings to show it on invoices.' ?></span>
                <button class="btn">Save invoice details</button>
            </div>
        </form>
    </section>
</div>
