<?php
/** The storefront block as a standalone page, shown in a sandboxed iframe in the editor. Expects $sale (array|null). */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Storefront preview</title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/shop.css')) ?>">
    <style>
        /* Preview only: nothing in the frame is clickable, and the page sits flush in the frame. */
        body.shop { pointer-events: none; padding: 0; margin: 0; }
        body.shop .pgrid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
        body.shop .pcard-quick-form, body.shop .pcard-heart-form { display: none; }
        body.shop .flash-preview-empty { padding: .25rem; }
        body.shop .flash-sec { margin-top: 0; }
    </style>
    <script src="<?= e(asset('js/flash-sales.js')) ?>" defer></script>
</head>
<body class="shop">
<?php if ($sale === null): ?>
    <p class="flash-preview-empty">Pick at least one product and an end time in the future to see the storefront block.</p>
<?php else: ?>
    <?php $flashSale = $sale; $preview = true; require BASE_PATH . '/app/Views/shop/_flash_block.php'; ?>
<?php endif; ?>
</body>
</html>
