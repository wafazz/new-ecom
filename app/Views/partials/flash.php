<?php
$success = \App\Core\Session::flash('success');
$formError = $_SESSION['_errors']['form'] ?? null;
?>
<?php if ($success): ?><div class="alert ok"><?= e($success) ?></div><?php endif; ?>
<?php if ($formError): ?><div class="alert bad"><?= e($formError) ?></div><?php endif; ?>
