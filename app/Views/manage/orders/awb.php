<?php
/** Printable AWB, one order. Expects $base, $order, $ship, $sender (array|null), $items (array|null), $autoPrint. Standalone page, no portal layout. */
$tn = (string) $ship['tracking_number'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex">
    <title>AWB <?= e($tn) ?></title>
    <style><?= file_get_contents(BASE_PATH . '/app/Views/manage/orders/_awb.css') ?></style>
</head>
<body>
<div class="screen">
    <button type="button" data-print>Print AWB</button>
    <button type="button" data-close>Close</button>
</div>

<div id="awb-root" data-print-on-load="<?= $autoPrint ? '1' : '0' ?>"
     data-printed-url="<?= e(url($base . '/orders/' . (int) $order['id'] . '/shipment/awb/printed')) ?>" data-token="<?= e(\App\Core\Csrf::token()) ?>">
    <div class="sheet"><?php require BASE_PATH . '/app/Views/manage/orders/_awb_label.php'; ?></div>
</div>

<script src="<?= e(asset('vendor/jsbarcode/JsBarcode.all.min.js')) ?>" defer></script>
<script src="<?= e(asset('vendor/qrcode/qrcode.js')) ?>" defer></script>
<script src="<?= e(asset('js/awb.js')) ?>" defer></script>
</body>
</html>
