<?php $appName = (string) config('app.name'); $siteLogo = \App\Services\SiteLogo::url(); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Sign in') ?> · <?= e($appName) ?></title>
    <script>(function(){try{var t=localStorage.getItem('theme');if(t!=='light'&&t!=='dark'){t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}document.documentElement.dataset.theme=t}catch(e){}})();</script>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%233558e8'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/shop.css')) ?>">
</head>
<body class="shop auth-body">
    <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><symbol id="i-check" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></symbol><symbol id="i-left" viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></symbol></svg>
    <div class="auth-wrap">
        <aside class="auth-art" aria-hidden="true">
            <a class="s-logo<?= $siteLogo ? ' s-logo-only' : '' ?>" href="<?= e(url('/')) ?>" tabindex="-1"><span class="s-logo-mark"><?php if ($siteLogo): ?><img class="s-logo-img" src="<?= e($siteLogo) ?>" alt="<?= e($appName) ?>"><?php else: ?><?= e(mb_strtoupper(mb_substr($appName, 0, 1))) ?><?php endif; ?></span><?php if (!$siteLogo): ?><span class="s-logo-text"><?= e($appName) ?></span><?php endif; ?></a>
            <h2>Good to see you.</h2>
            <p>Save your favourites, check out faster and follow every order from one place.</p>
            <ul><li><?= icon('check', 'i i-sm') ?> Order history and tracking</li><li><?= icon('check', 'i i-sm') ?> Saved addresses</li><li><?= icon('check', 'i i-sm') ?> A wishlist for later</li></ul>
        </aside>
        <div class="auth-card">
            <a class="brand" href="<?= e(url('/')) ?>"><?= icon('left', 'i i-sm') ?> Back to <?= e($appName) ?></a>
            <?php require BASE_PATH . '/app/Views/partials/flash.php'; ?>
            <?= $content ?>
        </div>
    </div>
</body>
</html>
<?php flash_clear(); ?>
