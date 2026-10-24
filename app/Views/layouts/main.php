<?php
use App\Core\Auth;
use App\Services\StoreFront;

$me = Auth::user();
$count = cart_count();
$nav = StoreFront::nav();
$isMember = StoreFront::isMember();
$wishCount = $isMember ? count(StoreFront::wished() ?? []) : 0;
$appName = (string) config('app.name');
$scripts = $scripts ?? [];
$pageTitle = ($title ?? '') !== '' ? $title . ' · ' . $appName : $appName;
$desc = (string) ($metaDescription ?? '');
$ico = fn(string $n, string $cls = 'i') => '<svg class="' . $cls . '" aria-hidden="true"><use href="#i-' . $n . '"/></svg>';
$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';
?>
<!doctype html>
<html lang="en" data-currency="<?= e((string) config('app.currency')) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <?php if ($desc !== ''): ?><meta name="description" content="<?= e($desc) ?>"><?php endif; ?>
    <meta property="og:site_name" content="<?= e($appName) ?>">
    <meta property="og:title" content="<?= e($pageTitle) ?>">
    <?php if ($desc !== ''): ?><meta property="og:description" content="<?= e($desc) ?>"><?php endif; ?>
    <?php if (!empty($metaImage)): ?><meta property="og:image" content="<?= e(url($metaImage)) ?>"><?php endif; ?>
    <meta name="csrf-token" content="<?= e(\App\Core\Csrf::token()) ?>">
    <meta name="theme-color" content="#3b5bdb">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%233558e8'/%3E%3Ctext x='16' y='22' font-family='Arial' font-size='18' font-weight='700' text-anchor='middle' fill='white'%3E<?= e(mb_strtoupper(mb_substr($appName, 0, 1))) ?>%3C/text%3E%3C/svg%3E">
    <script>(function(){try{var t=localStorage.getItem('theme');if(t!=='light'&&t!=='dark'){t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}document.documentElement.dataset.theme=t}catch(e){}})();</script>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/shop.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/sweetalert2/sweetalert2.min.css')) ?>">
    <script src="<?= e(asset('vendor/sweetalert2/sweetalert2.all.min.js')) ?>" defer></script>
    <script src="<?= e(asset('js/confirm.js')) ?>" defer></script>
    <script src="<?= e(asset('js/shop.js')) ?>" defer></script>
    <?php if (in_array('select2', $vendor ?? [], true)): ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/select2/select2.min.css')) ?>">
    <script src="<?= e(asset('vendor/select2/jquery.min.js')) ?>" defer></script>
    <script src="<?= e(asset('vendor/select2/select2.min.js')) ?>" defer></script>
    <?php endif; ?>
    <?php foreach ($scripts as $__js): ?><script src="<?= e(asset('js/' . $__js)) ?>" defer></script><?php endforeach; ?>
</head>
<body class="shop" data-home="<?= e(url('/')) ?>" data-member="<?= $isMember ? '1' : '0' ?>" data-login="<?= e(url('/login')) ?>" data-cart-url="<?= e(url('/cart')) ?>" data-checkout-url="<?= e(url('/checkout')) ?>" data-mini="<?= e(url('/cart/mini')) ?>" data-suggest="<?= e(url('/search/suggest')) ?>">
<a class="skip-link" href="#main">Skip to content</a>

<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></symbol>
    <symbol id="i-bag" viewBox="0 0 24 24"><path d="M5 8h14l-1.2 12H6.2L5 8z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/></symbol>
    <symbol id="i-heart" viewBox="0 0 24 24"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/></symbol>
    <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></symbol>
    <symbol id="i-menu" viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"/></symbol>
    <symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></symbol>
    <symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></symbol>
    <symbol id="i-moon" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></symbol>
    <symbol id="i-down" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></symbol>
    <symbol id="i-right" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></symbol>
    <symbol id="i-left" viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="i-minus" viewBox="0 0 24 24"><path d="M5 12h14"/></symbol>
    <symbol id="i-trash" viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></symbol>
    <symbol id="i-truck" viewBox="0 0 24 24"><path d="M1 4h14v12H1z"/><path d="M15 8h4l4 4v4h-8V8z"/><circle cx="5.5" cy="18.5" r="2"/><circle cx="18.5" cy="18.5" r="2"/></symbol>
    <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/></symbol>
    <symbol id="i-pin" viewBox="0 0 24 24"><path d="M21 10c0 7-9 12-9 12S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></symbol>
    <symbol id="i-tag" viewBox="0 0 24 24"><path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.2"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7"/></symbol>
    <symbol id="i-filter" viewBox="0 0 24 24"><path d="M3 5h18l-7 8v6l-4 2v-8L3 5z"/></symbol>
    <symbol id="i-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
    <symbol id="i-box" viewBox="0 0 24 24"><path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></symbol>
    <symbol id="i-out" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></symbol>
    <symbol id="i-zoom" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5M11 8v6M8 11h6"/></symbol>
</svg>

<?php if ($nav['shipping'] !== null || $nav['hasSale']): ?>
<div class="s-announce">
    <div class="s-wrap">
        <?php if ($nav['hasSale']): ?><a href="<?= e(url('/products?sale=1')) ?>"><?= $ico('tag') ?> Sale is on: see what's discounted</a><?php endif; ?>
        <?php if ($nav['shipping'] !== null): ?><span><?= $ico('truck') ?> Flat-rate shipping <?= e($nav['shipping']) ?></span><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<header class="s-header" id="s-header">
    <div class="s-wrap s-bar">
        <button type="button" class="s-icon-btn s-burger" data-menu-open aria-label="Open menu" aria-controls="s-menu"><?= $ico('menu') ?></button>
        <a class="s-logo" href="<?= e(url('/')) ?>" aria-label="<?= e($appName) ?> home"><span class="s-logo-mark" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($appName, 0, 1))) ?></span><span class="s-logo-text"><?= e($appName) ?></span></a>

        <form class="s-search" role="search" method="get" action="<?= e(url('/products')) ?>" data-search autocomplete="off">
            <?= $ico('search', 'i s-search-ico') ?>
            <input type="search" name="q" placeholder="Search products, brands, categories…" value="<?= e(is_string($_GET['q'] ?? null) ? $_GET['q'] : '') ?>" aria-label="Search products" role="combobox" aria-expanded="false" aria-controls="s-suggest" aria-autocomplete="list">
            <div class="s-suggest" id="s-suggest" role="listbox" hidden></div>
        </form>

        <div class="s-actions">
            <button type="button" class="s-icon-btn" data-theme-toggle aria-label="Switch between light and dark" title="Light / dark"><?= $ico('moon', 'i i-moon') ?><?= $ico('sun', 'i i-sun') ?></button>
            <a class="s-icon-btn s-wish-link" href="<?= e(url($isMember ? '/member/wishlist' : '/login')) ?>" aria-label="<?= $isMember ? 'Wishlist' : 'Log in to see your wishlist' ?>" title="Wishlist"><?= $ico('heart') ?><?php if ($wishCount > 0): ?><span class="s-badge" data-wish-count><?= (int) $wishCount ?></span><?php else: ?><span class="s-badge" data-wish-count hidden>0</span><?php endif; ?></a>
            <?php if ($me): ?>
                <details class="s-menu s-account">
                    <summary class="s-icon-btn" aria-label="Account menu"><span class="s-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $me['name'], 0, 1))) ?></span></summary>
                    <div class="s-pop">
                        <p class="s-pop-head"><strong><?= e($me['name']) ?></strong><small><?= e($me['role']) ?></small></p>
                        <a href="<?= e(url(Auth::HOME[$me['role']])) ?>"><?= $ico('user') ?> <?= $me['role'] === 'member' ? 'My account' : 'Open ' . e($me['role']) . ' portal' ?></a>
                        <?php if ($me['role'] === 'member'): ?>
                            <a href="<?= e(url('/member/orders')) ?>"><?= $ico('box') ?> My orders</a>
                            <a href="<?= e(url('/member/wishlist')) ?>"><?= $ico('heart') ?> Wishlist</a>
                        <?php endif; ?>
                        <a href="<?= e(url('/track')) ?>"><?= $ico('pin') ?> Track an order</a>
                        <a href="<?= e(url(Auth::HOME[$me['role']] . '/profile')) ?>"><?= $ico('user') ?> Profile</a>
                        <a href="<?= e(url(Auth::HOME[$me['role']] . '/password')) ?>"><?= $ico('shield') ?> Password</a>
                        <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button><?= $ico('out') ?> Log out</button></form>
                    </div>
                </details>
            <?php else: ?>
                <a class="s-login" href="<?= e(url('/login')) ?>"><?= $ico('user') ?><span>Log in</span></a>
            <?php endif; ?>
            <button type="button" class="s-icon-btn s-cart-btn" data-cart-open aria-label="Open cart" aria-controls="cart-drawer"><?= $ico('bag') ?><span class="s-badge" data-cart-count<?= $count > 0 ? '' : ' hidden' ?>><?= (int) $count ?></span></button>
        </div>
    </div>

    <nav class="s-cats" aria-label="Shop categories">
        <div class="s-wrap">
            <a href="<?= e(url('/products')) ?>" class="<?= $path === '/products' && empty($_GET['category']) && empty($_GET['sale']) ? 'on' : '' ?>">All products</a>
            <?php foreach (array_slice($nav['categories'], 0, 8) as $c): $kids = $c['children']; ?>
                <div class="s-cat<?= $kids !== [] ? ' has-kids' : '' ?>">
                    <a href="<?= e(url('/products?category=' . $c['slug'])) ?>" class="<?= ($_GET['category'] ?? '') === $c['slug'] ? 'on' : '' ?>"><?= e($c['name']) ?><?= $kids !== [] ? $ico('down', 'i i-sm') : '' ?></a>
                    <?php if ($kids !== []): ?>
                        <div class="s-drop">
                            <a href="<?= e(url('/products?category=' . $c['slug'])) ?>">All <?= e($c['name']) ?> <span><?= (int) $c['n'] ?></span></a>
                            <?php foreach ($kids as $k): ?><a href="<?= e(url('/products?category=' . $k['slug'])) ?>"><?= e($k['name']) ?> <span><?= (int) $k['n'] ?></span></a><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($nav['hasSale']): ?><a href="<?= e(url('/products?sale=1')) ?>" class="s-sale-link <?= !empty($_GET['sale']) ? 'on' : '' ?>"><?= $ico('tag') ?> Sale</a><?php endif; ?>
        </div>
    </nav>
</header>

<!-- mobile menu -->
<aside class="s-menu-drawer" id="s-menu" aria-label="Menu" aria-hidden="true">
    <div class="s-drawer-head"><strong><?= e($appName) ?></strong><button type="button" class="s-icon-btn" data-menu-close aria-label="Close menu"><?= $ico('x') ?></button></div>
    <div class="s-drawer-body">
        <a href="<?= e(url('/products')) ?>">All products</a>
        <?php foreach ($nav['categories'] as $c): ?>
            <details<?= ($_GET['category'] ?? '') === $c['slug'] ? ' open' : '' ?>>
                <summary><?= e($c['name']) ?> <span class="muted"><?= (int) $c['n'] ?></span></summary>
                <a href="<?= e(url('/products?category=' . $c['slug'])) ?>">All <?= e($c['name']) ?></a>
                <?php foreach ($c['children'] as $k): ?><a href="<?= e(url('/products?category=' . $k['slug'])) ?>"><?= e($k['name']) ?></a><?php endforeach; ?>
            </details>
        <?php endforeach; ?>
        <?php if ($nav['hasSale']): ?><a class="s-sale-link" href="<?= e(url('/products?sale=1')) ?>"><?= $ico('tag') ?> Sale</a><?php endif; ?>
        <hr>
        <a href="<?= e(url('/track')) ?>">Track an order</a>
        <?php if ($me): ?>
            <a href="<?= e(url(Auth::HOME[$me['role']])) ?>">My account</a>
        <?php else: ?>
            <a href="<?= e(url('/login')) ?>">Log in</a>
            <a href="<?= e(url('/register')) ?>">Create an account</a>
        <?php endif; ?>
    </div>
</aside>

<!-- cart drawer -->
<aside class="cart-drawer" id="cart-drawer" role="dialog" aria-modal="true" aria-label="Your cart" aria-hidden="true">
    <div class="s-drawer-head"><strong>Your cart <span class="muted" data-cart-title-count></span></strong><button type="button" class="s-icon-btn" data-cart-close aria-label="Close cart"><?= $ico('x') ?></button></div>
    <div class="cart-body" id="cart-lines" aria-live="polite"><p class="muted cart-loading">Loading…</p></div>
    <div class="cart-foot" id="cart-foot" hidden></div>
</aside>
<div class="s-backdrop" data-backdrop></div>

<main id="main">
<?php if (!empty($bleed)): ?>
    <div class="s-wrap"><?php require BASE_PATH . '/app/Views/partials/flash.php'; ?></div>
    <?= $content ?>
<?php else: ?>
    <div class="s-wrap s-page">
        <?php require BASE_PATH . '/app/Views/partials/flash.php'; ?>
        <?= $content ?>
    </div>
<?php endif; ?>
</main>

<footer class="s-footer">
    <div class="s-wrap s-foot-grid">
        <div class="s-foot-brand">
            <a class="s-logo" href="<?= e(url('/')) ?>"><span class="s-logo-mark" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($appName, 0, 1))) ?></span><span class="s-logo-text"><?= e($appName) ?></span></a>
            <p>Everyday favourites, delivered to your door. Browse the collection, save what you like and check out as a guest or with an account.</p>
        </div>
        <div>
            <h3>Shop</h3>
            <a href="<?= e(url('/products')) ?>">All products</a>
            <?php if ($nav['hasSale']): ?><a href="<?= e(url('/products?sale=1')) ?>">On sale</a><?php endif; ?>
            <?php foreach (array_slice($nav['categories'], 0, 4) as $c): ?><a href="<?= e(url('/products?category=' . $c['slug'])) ?>"><?= e($c['name']) ?></a><?php endforeach; ?>
        </div>
        <div>
            <h3>Help</h3>
            <a href="<?= e(url('/track')) ?>">Track your order</a>
            <a href="<?= e(url('/cart')) ?>">Your cart</a>
            <a href="<?= e(url('/checkout')) ?>">Checkout</a>
        </div>
        <div>
            <h3>Account</h3>
            <?php if ($me): ?>
                <a href="<?= e(url(Auth::HOME[$me['role']])) ?>">My account</a>
                <?php if ($isMember): ?><a href="<?= e(url('/member/orders')) ?>">My orders</a><a href="<?= e(url('/member/wishlist')) ?>">Wishlist</a><?php endif; ?>
            <?php else: ?>
                <a href="<?= e(url('/login')) ?>">Log in</a>
                <a href="<?= e(url('/register')) ?>">Create an account</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="s-wrap s-foot-bottom">
        <span>© <?= date('Y') ?> <?= e($appName) ?></span>
        <?php if ($nav['methods'] !== []): ?><span class="s-pay"><?= $ico('shield') ?> Pay by <?= e(implode(' · ', $nav['methods'])) ?></span><?php endif; ?>
    </div>
</footer>

<div class="s-toasts" id="s-toasts" aria-live="polite" role="region" aria-label="Notifications"></div>
<button type="button" class="s-top" data-top aria-label="Back to top" hidden><?= $ico('arrow', 'i i-up') ?></button>
</body>
</html>
<?php flash_clear(); ?>
