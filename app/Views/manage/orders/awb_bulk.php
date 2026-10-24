<?php
/** Several AWB labels in one print run. Expects $base, $labels ([['order','ship','items'], ...]), $sender, $printedIds (csv), $autoPrint. */
$count = count($labels);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex">
    <title>AWB × <?= (int) $count ?></title>
    <style><?= file_get_contents(BASE_PATH . '/app/Views/manage/orders/_awb.css') ?></style>
</head>
<body>
<div class="screen">
    <span><?= (int) $count ?> label<?= $count === 1 ? '' : 's' ?></span>
    <button type="button" data-print>Print all</button>
    <button type="button" data-close>Close</button>
</div>

<div id="awb-root" data-print-on-load="<?= $autoPrint ? '1' : '0' ?>"
     data-printed-url="<?= e(url($base . '/orders/awb/printed')) ?>" data-printed-ids="<?= e($printedIds) ?>" data-token="<?= e(\App\Core\Csrf::token()) ?>">
    <?php foreach ($labels as $l):
        $order = $l['order'];
        $ship = $l['ship'];
        $items = $l['items'];
    ?>
        <div class="sheet"><?php require BASE_PATH . '/app/Views/manage/orders/_awb_label.php'; ?></div>
    <?php endforeach; ?>
</div>

<script src="<?= e(asset('vendor/jsbarcode/JsBarcode.all.min.js')) ?>" defer></script>
<script src="<?= e(asset('vendor/qrcode/qrcode.js')) ?>" defer></script>
<script src="<?= e(asset('js/awb.js')) ?>" defer></script>
</body>
</html>
