<div class="msg-page">
    <header class="msg-head">
        <div>
            <p class="muted" style="margin:0"><a href="<?= e(url('/member/addresses')) ?>">← Addresses</a></p>
            <h1>Edit address</h1>
        </div>
    </header>
    <section class="msg-card">
        <?php $action = '/member/addresses/' . (int) $a['id']; $submit = 'Save changes'; require BASE_PATH . '/app/Views/member/addresses/_form.php'; ?>
    </section>
</div>
