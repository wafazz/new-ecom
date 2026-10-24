<?php
$pid = (int) $product['id'];
$formAction = $base . "/products/{$pid}";
?>
<p><a href="<?= e(url($base . '/products')) ?>">← Products</a></p>
<h1><?= e($product['name']) ?></h1>
<?php require __DIR__ . '/_form.php'; ?>
