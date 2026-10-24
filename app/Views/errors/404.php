<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>404</title><link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>"></head>
<body><main class="container"><h1>404</h1><p>Page not found.<?= !empty($message) ? ' ' . e($message) : '' ?></p><p><a href="<?= e(url('/')) ?>">Back home</a></p></main></body></html>
