<?php
use App\Core\Auth;
use App\Core\Database;

$me = Auth::user();
$role = $me['role'];
$home = Auth::HOME[$role];

// Sidebar badges: work waiting for a person (admin sees orders + commissions, staff only orders they may view).
$canOrders = $role === 'admin' || ($role === 'staff' && Auth::can('orders.view'));
$orderCounts = $canOrders ? Database::query('SELECT status, COUNT(*) FROM orders GROUP BY status')->fetchAll(\PDO::FETCH_KEY_PAIR) : [];
$pendingOrders = (int) ($orderCounts['pending'] ?? 0);
// Orders sub-menu: All + one entry per status, each with its live count. [href, label, status key, count]
$orderSub = function (string $portal) use ($orderCounts): array {
    $kids = [[$portal . '/orders', 'All orders', '', (int) array_sum($orderCounts)]];
    foreach (array_keys(\App\Services\OrderService::FLOW) as $s) {
        $kids[] = [$portal . '/orders?status=' . $s, ucfirst($s), $s, (int) ($orderCounts[$s] ?? 0)];
    }
    return $kids;
};
$pendingCommissions = $role === 'admin' ? (int) Database::query("SELECT COUNT(*) FROM affiliate_commissions WHERE status = 'pending'")->fetchColumn() : 0;

// [group => [[href, label, icon, badge]]]
$groups = match ($role) {
    'admin' => [
        'Overview' => [['/admin', 'Dashboard', 'home', 0]],
        'Catalog' => [['/admin/products', 'Products', 'box', 0], ['/admin/categories', 'Categories', 'tag', 0], ['/admin/brands', 'Brands', 'tag', 0]],
        'Inventory' => [['/admin/stock', 'Stock Control', 'stack', 0], ['/admin/stock/movements', 'Stock Movement', 'swap', 0]],
        'Sales' => [['/admin/orders', 'Orders', 'cart', $pendingOrders, $orderSub('/admin')], ['/admin/affiliates', 'Affiliates', 'link', 0], ['/admin/commissions', 'Commissions', 'coin', $pendingCommissions], ['/admin/withdrawals', 'Withdrawals', 'coin', 0], ['/admin/vouchers', 'Vouchers', 'coin', 0]],
        'Administration' => [['/admin/staff', 'Staff', 'users', 0], ['/admin/visitors', 'Visitors', 'pin', 0], ['/admin/shipping-rates', 'Shipping rates', 'truck', 0], ['/admin/invoice', 'Invoice details', 'stack', 0], ['/admin/backup', 'Backup', 'lock', 0], ['/admin/email/announce', 'Announcements', 'heart', 0], ['/admin/payments', 'Payments', 'card', 0], ['/admin/api', 'API Management', 'link', 0, [['/admin/whatsapp', 'WhatsApp', '', 0], ['/admin/email', 'Email', '', 0], ['/admin/payments/billplz', 'Billplz', '', 0], ['/admin/shipping/ninjavan', 'NinjaVan', '', 0]]], ['/admin/settings', 'Settings', 'gear', 0]],
        'Account' => [['/admin/profile', 'Profile', 'user', 0], ['/admin/password', 'Password', 'lock', 0]],
    ],
    'staff' => array_filter([
        'Overview' => [['/staff', 'Dashboard', 'home', 0]],
        'Catalog' => Auth::can('products.manage') ? [['/staff/products', 'Products', 'box', 0], ['/staff/categories', 'Categories', 'tag', 0], ['/staff/brands', 'Brands', 'tag', 0]] : [],
        'Inventory' => Auth::can('stock.manage') ? [['/staff/stock', 'Stock Control', 'stack', 0], ['/staff/stock/movements', 'Stock Movement', 'swap', 0]] : [],
        'Sales' => Auth::can('orders.view') ? [['/staff/orders', 'Orders', 'cart', $pendingOrders, $orderSub('/staff')]] : [],
        'Administration' => array_merge(
            Auth::can('payments.manage') ? [['/staff/payments', 'Payments', 'card', 0]] : [],
            (Auth::can('payments.manage') || Auth::can('shipping.manage')) ? [['/staff/api', 'API Management', 'link', 0, array_merge(
                Auth::can('payments.manage') ? [['/staff/payments/billplz', 'Billplz', '', 0]] : [],
                Auth::can('shipping.manage') ? [['/staff/shipping/ninjavan', 'NinjaVan', '', 0]] : []
            )]] : []
        ),
        'Account' => [['/staff/profile', 'Profile', 'user', 0], ['/staff/password', 'Password', 'lock', 0]],
    ]),
    default => [
        'Overview' => [['/member', 'Overview', 'home', 0]],
        'Shopping' => [['/member/orders', 'Orders', 'cart', 0], ['/member/wishlist', 'Wishlist', 'heart', 0], ['/member/addresses', 'Addresses', 'pin', 0]],
        'Earn' => [['/member/affiliate', 'Affiliate', 'link', 0]],
        'Account' => [['/member/profile', 'Profile', 'user', 0], ['/member/password', 'Password', 'lock', 0]],
    ],
};

// Active item = longest matching href; the dashboard roots match exactly so they don't light up everywhere.
$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';
$base = rtrim((string) parse_url((string) env('APP_URL', ''), PHP_URL_PATH), '/');
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base)) ?: '/';
}
$active = null;
parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY), $__q);
$statusNow = is_string($__q['status'] ?? null) && isset(\App\Services\OrderService::FLOW[$__q['status']]) ? $__q['status'] : '';
foreach ($groups as $items) {
    foreach ($items as $item) {
        // A group's own page and the pages inside its submenu both count as the current section.
        foreach (array_merge([$item[0]], array_column($item[4] ?? [], 0)) as $href) {
            $hit = $href === $home ? $path === $href : ($path === $href || str_starts_with($path, $href . '/'));
            if ($hit && ($active === null || strlen($href) > strlen($active))) {
                $active = $href;
            }
        }
    }
}

$icons = [
    'home' => '<path d="M3 11l9-8 9 8v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
    'box' => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
    'stack' => '<path d="M12 2l9 5-9 5-9-5 9-5z"/><path d="M3 12l9 5 9-5"/><path d="M3 17l9 5 9-5"/>',
    'swap' => '<path d="M7 7h13M16 3l4 4-4 4"/><path d="M17 17H4M8 13l-4 4 4 4"/>',
    'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/><path d="M12 15v2"/>',
    'tag' => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.2"/>',
    'cart' => '<circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/><path d="M2 3h3l2.7 12.4a1 1 0 0 0 1 .8h9.1a1 1 0 0 0 1-.8L21 7H6"/>',
    'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8"/><path d="M18 14a6 6 0 0 1 3.5 5.5"/>',
    'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
    'coin' => '<circle cx="12" cy="12" r="9"/><path d="M14.8 9a3 3 0 0 0-2.8-1.5c-1.7 0-3 .9-3 2.2 0 3 6 1.6 6 4.6 0 1.3-1.3 2.2-3 2.2A3.2 3.2 0 0 1 9 14.6"/><path d="M12 6v1.5M12 16.5V18"/>',
    'truck' => '<path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
    'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
    'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/>',
    'pin' => '<path d="M21 10c0 7-9 12-9 12S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
    'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    'moon' => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
    'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
    'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
    'chev' => '<path d="M11 17l-5-5 5-5M18 17l-5-5 5-5"/>',
    'out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
    'shop' => '<path d="M3 9l1.5-5h15L21 9"/><path d="M4 9v11h16V9"/><path d="M9 20v-6h6v6"/>',
];
$svg = fn(string $name, string $class = 'ico') => '<svg class="' . $class . '" viewBox="0 0 24 24" aria-hidden="true">' . $icons[$name] . '</svg>';
$appName = (string) config('app.name');
$siteLogo = \App\Services\SiteLogo::url(); // the logo replaces the shop name wherever it shows
?>
<!doctype html>
<html lang="en" data-currency="<?= e((string) config('app.currency')) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? '') ?> · <?= e($appName) ?></title>
    <script>(function(){try{var t=localStorage.getItem('theme');if(t!=='light'&&t!=='dark'){t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}document.documentElement.dataset.theme=t;if(localStorage.getItem('sidebar')==='collapsed'){document.documentElement.dataset.sidebar='collapsed'}}catch(e){}})();</script>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/portal.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/invoice.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/sweetalert2/sweetalert2.min.css')) ?>">
    <script src="<?= e(asset('vendor/sweetalert2/sweetalert2.all.min.js')) ?>" defer></script>
    <script src="<?= e(asset('js/confirm.js')) ?>" defer></script>
    <script src="<?= e(asset('js/portal.js')) ?>" defer></script>
    <?php if (in_array('quill', $vendor ?? [], true)): ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/quill/quill.snow.css')) ?>">
    <script src="<?= e(asset('vendor/quill/quill.js')) ?>" defer></script>
    <?php endif; ?>
    <?php if (in_array('select2', $vendor ?? [], true)): ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/select2/select2.min.css')) ?>">
    <script src="<?= e(asset('vendor/select2/jquery.min.js')) ?>" defer></script>
    <script src="<?= e(asset('vendor/select2/select2.min.js')) ?>" defer></script>
    <?php endif; ?>
    <?php foreach (($scripts ?? []) as $__js): ?><script src="<?= e(asset('js/' . $__js)) ?>" defer></script><?php endforeach; ?>
</head>
<body class="portal"<?= in_array($role, ['admin', 'staff'], true) ? ' data-search="' . e(url($home . '/search')) . '"' : '' ?>>
<div class="shell">
    <aside class="sidebar" id="sidebar" aria-label="Main navigation">
        <div class="sidebar-head">
            <span class="logo<?= $siteLogo ? ' logo-only' : '' ?>" aria-hidden="true"><?php if ($siteLogo): ?><img class="s-logo-img" src="<?= e($siteLogo) ?>" alt="<?= e($appName) ?>"><?php else: ?><?= e(mb_strtoupper(mb_substr($appName, 0, 1))) ?><?php endif; ?></span>
            <?php if (!$siteLogo): ?><span class="brand-text"><strong><?= e($appName) ?></strong><small><?= e($role === 'member' ? 'My account' : $role . ' portal') ?></small></span><?php endif; ?>
        </div>
        <nav class="nav">
            <?php foreach ($groups as $group => $items): if ($items === []) { continue; } ?>
                <div class="nav-group">
                    <div class="nav-title"><?= e($group) ?></div>
                    <?php foreach ($items as $item): [$href, $label, $icon, $badge] = $item; $kids = $item[4] ?? []; ?>
                        <?php if ($kids === []): ?>
                        <a href="<?= e(url($href)) ?>" title="<?= e($label) ?>"<?= $href === $active ? ' aria-current="page"' : '' ?>>
                            <?= $svg($icon) ?><span class="nav-label"><?= e($label) ?></span>
                            <?php if ($badge > 0): ?><span class="pill" aria-label="<?= (int) $badge ?> pending"><?= $badge > 99 ? '99+' : (int) $badge ?></span><?php endif; ?>
                        </a>
                        <?php else:
                            $inSection = $href === $active || in_array($active, array_column($kids, 0), true);
                            $onList = $inSection && $path === $href;               // the orders list itself: a child is the current page
                            $subId = 'sub-' . substr(md5($href), 0, 6);
                        ?>
                        <div class="nav-item has-sub<?= $inSection ? ' open' : '' ?><?= $inSection ? ' in-section' : '' ?>" data-nav-sub="<?= e($subId) ?>">
                            <button type="button" class="nav-toggle nav-parent<?= $inSection && !$onList ? ' is-current' : '' ?>" title="<?= e($label) ?>" aria-expanded="<?= $inSection ? 'true' : 'false' ?>" aria-controls="<?= e($subId) ?>">
                                <?= $svg($icon) ?><span class="nav-label"><?= e($label) ?></span>
                                <?php if ($badge > 0): ?><span class="pill" aria-label="<?= (int) $badge ?> pending"><?= $badge > 99 ? '99+' : (int) $badge ?></span><?php endif; ?>
                                <svg class="ico nav-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                            </button>
                            <div class="nav-sub" id="<?= e($subId) ?>">
                                <?php foreach ($kids as [$kHref, $kLabel, $kKey, $kCount]): $on = ($onList && $kKey === $statusNow) || (!$onList && $kHref === $active); ?>
                                    <a href="<?= e(url($kHref)) ?>" data-palette-label="<?= e($label . ': ' . $kLabel) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
                                        <span class="dot st-<?= e($kKey === '' ? 'all' : $kKey) ?>" aria-hidden="true"></span><span class="nav-label"><?= e($kLabel) ?></span>
                                        <span class="count<?= $kKey === 'pending' && $kCount > 0 ? ' hot' : '' ?>"><?= $kCount > 999 ? '999+' : (int) $kCount ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">
            <button type="button" data-sidebar-collapse aria-expanded="true" title="Collapse sidebar"><?= $svg('chev') ?><span class="foot-label">Collapse</span></button>
        </div>
    </aside>
    <div class="backdrop" aria-hidden="true"></div>

    <div class="main">
        <header class="topbar">
            <button type="button" class="icon-btn menu" data-nav-open aria-controls="sidebar" aria-expanded="false" aria-label="Open menu"><?= $svg('menu') ?></button>
            <span class="page"><?= e($title ?? '') ?></span>
            <span class="spacer"></span>
            <button type="button" class="search-pill" data-palette-open aria-label="Search and commands (Ctrl or Command K)">
                <?= $svg('search') ?><span>Search…</span><kbd>⌘K</kbd>
            </button>
            <a class="icon-btn" href="<?= e(url('/')) ?>" title="View shop" aria-label="View shop"><?= $svg('shop') ?></a>
            <button type="button" class="icon-btn theme-toggle" data-theme-toggle aria-pressed="false" title="Toggle dark mode" aria-label="Toggle dark mode"><?= $svg('sun', 'ico sun') ?><?= $svg('moon', 'ico moon') ?></button>
            <div class="user">
                <a class="me" href="<?= e(url($home . '/profile')) ?>" title="Your profile">
                    <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $me['name'], 0, 1))) ?></span>
                    <span class="who"><strong><?= e($me['name']) ?></strong><small><?= e($role) ?></small></span>
                </a>
                <form method="post" action="<?= e(url('/logout')) ?>" class="inline"><?= csrf_field() ?>
                    <button class="icon-btn" title="Log out" aria-label="Log out"><?= $svg('out') ?></button>
                </form>
            </div>
        </header>
        <main class="content">
            <?php require BASE_PATH . '/app/Views/partials/flash.php'; ?>
            <?= $content ?>
        </main>
    </div>
</div>
<?php require BASE_PATH . '/app/Views/partials/invoice_modal.php'; ?>
<script src="<?= e(asset('js/invoice-modal.js')) ?>" defer></script>
</body>
</html>
<?php flash_clear(); ?>
