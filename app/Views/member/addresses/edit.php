<p><a href="<?= e(url('/member/addresses')) ?>">← Addresses</a></p>
<h1>Edit address</h1>
<?php $action = '/member/addresses/' . (int) $a['id']; $submit = 'Save changes'; require BASE_PATH . '/app/Views/member/addresses/_form.php'; ?>
