<?php
use App\Core\Router;

return function (Router $r): void {
    // Billplz webhook: server-to-server, authenticated by its X-Signature (so deliberately outside the CSRF group).
    $r->post('/payments/billplz/callback', 'Shop/BillplzController@callback');
    $r->post('/shipping/ninjavan/webhook', 'Shop/NinjaVanWebhookController@receive');

    // CSRF protects every non-GET request in every group below.
    $r->group('', ['csrf'], function (Router $r): void {

        // Public shop
        $r->get('/', 'Shop/HomeController@index');
        $r->get('/health', 'Shop/HomeController@health');
        $r->get('/live/visitors', 'Shop/HomeController@liveVisitors');
        $r->get('/verify-email/{token}', 'Auth/VerifyEmailController@confirm');
        $r->get('/install', 'InstallController@show');
        $r->post('/install/check', 'InstallController@check');
        $r->post('/install/finish', 'InstallController@finish');
        $r->get('/products', 'Shop/CatalogController@index');
        $r->get('/products/{slug}', 'Shop/CatalogController@show');
        $r->post('/wishlist/toggle', 'Shop/WishlistController@toggle', ['auth:member']);
        $r->get('/payments/billplz/return', 'Shop/BillplzController@back');
        $r->post('/order/{orderNo}/{token}/pay', 'Shop/OrderTrackController@pay');
        $r->get('/r/{code}', 'Shop/AffiliateRedirectController@go');
        $r->get('/search/suggest', 'Shop/SearchController@suggest');
        $r->get('/cart/mini', 'Shop/CartController@mini');
        $r->get('/cart', 'Shop/CartController@show');
        $r->post('/cart/add', 'Shop/CartController@add');
        $r->post('/cart/update', 'Shop/CartController@update');
        $r->post('/cart/remove', 'Shop/CartController@remove');

        // Checkout (members and guests) + guest order access
        $r->get('/checkout', 'Shop/CheckoutController@show');
        $r->post('/checkout', 'Shop/CheckoutController@place');
        $r->get('/track', 'Shop/OrderTrackController@form');
        $r->post('/track', 'Shop/OrderTrackController@lookup');
        $r->get('/order/{orderNo}/{token}', 'Shop/OrderTrackController@show');
        $r->post('/order/{orderNo}/{token}/cancel', 'Shop/OrderTrackController@cancel');
        $r->post('/order/{orderNo}/{token}/receipt', 'Shop/OrderTrackController@receipt');
        $r->get('/order/{orderNo}/{token}/receipt/{id}', 'Shop/OrderTrackController@receiptFile');

        // Guest-only pages (logged-in users are bounced to their dashboard)
        $r->group('', ['guest'], function (Router $r): void {
            $r->get('/login', 'Auth/MemberLoginController@show');
            $r->post('/login', 'Auth/MemberLoginController@login');
            $r->get('/register', 'Auth/RegisterController@show');
            $r->post('/register', 'Auth/RegisterController@register');
            $r->get('/admin/login', 'Auth/AdminLoginController@show');
            $r->post('/admin/login', 'Auth/AdminLoginController@login');
            $r->get('/staff/login', 'Auth/StaffLoginController@show');
            $r->post('/staff/login', 'Auth/StaffLoginController@login');
            $r->get('/forgot-password', 'Auth/PasswordResetController@show');
            $r->post('/forgot-password', 'Auth/PasswordResetController@send');
            $r->get('/reset-password/{token}', 'Auth/PasswordResetController@form');
            $r->post('/reset-password', 'Auth/PasswordResetController@update');
        });

        $r->post('/logout', 'Auth/LogoutController@logout', ['auth']);

        // Product + category management, mounted in both the admin and staff portals.
        $manage = function (Router $r): void {
            $r->get('/products', 'Manage/ProductController@index');
            $r->get('/products/create', 'Manage/ProductController@create');
            $r->post('/products', 'Manage/ProductController@store');
            $r->post('/products/bulk', 'Manage/ProductController@bulk');
            $r->post('/products/{id}/quick', 'Manage/ProductController@quick');
            $r->get('/products/{id}/edit', 'Manage/ProductController@edit');
            $r->post('/products/{id}', 'Manage/ProductController@update');
            $r->post('/products/{id}/delete', 'Manage/ProductController@destroy');
            $r->get('/categories', 'Manage/CategoryController@index');
            $r->post('/categories', 'Manage/CategoryController@store');
            $r->post('/categories/{id}', 'Manage/CategoryController@update');
            $r->post('/categories/{id}/delete', 'Manage/CategoryController@destroy');
            $r->get('/brands', 'Manage/BrandController@index');
            $r->post('/brands', 'Manage/BrandController@store');
            $r->post('/brands/{id}', 'Manage/BrandController@update');
            $r->post('/brands/{id}/delete', 'Manage/BrandController@destroy');
        };
        $r->group('/admin', ['auth:admin'], $manage);
        $r->group('/staff', ['auth:staff', 'permission:products.manage'], $manage);

        // Command-palette search (results are filtered by permission inside the controller).
        $r->group('/admin', ['auth:admin'], fn(Router $r) => $r->get('/search', 'Manage/SearchController@index'));
        $r->group('/staff', ['auth:staff'], fn(Router $r) => $r->get('/search', 'Manage/SearchController@index'));

        // Order management: admin passes every permission check, staff need orders.view / orders.update.
        $orders = function (Router $r): void {
            $r->get('/orders', 'Manage/OrderController@index', ['permission:orders.view']);
            $r->get('/orders/{id}', 'Manage/OrderController@show', ['permission:orders.view']);
            $r->post('/orders/{id}/status', 'Manage/OrderController@status', ['permission:orders.update']);
            $r->post('/orders/{id}/approve', 'Manage/OrderController@approve', ['permission:orders.update']);
            $r->post('/orders/{id}/delete', 'Manage/OrderController@destroy', ['permission:orders.update']);
            $r->post('/orders/move-next', 'Manage/OrderController@moveNext', ['permission:orders.update']);
            $r->post('/orders/{id}/details', 'Manage/OrderController@details', ['permission:orders.update']);
            $r->post('/orders/{id}/paid', 'Manage/OrderController@paid', ['permission:orders.update']);
            $r->get('/orders/{id}/proofs/{proofId}', 'Manage/OrderController@proofFile', ['permission:orders.view']);
            $r->post('/orders/{id}/proofs/{proofId}/approve', 'Manage/OrderController@approveProof', ['permission:orders.update']);
            $r->post('/orders/{id}/proofs/{proofId}/reject', 'Manage/OrderController@rejectProof', ['permission:orders.update']);
            $r->post('/orders/{id}/shipment', 'Manage/ShipmentController@create', ['permission:orders.update']);
            $r->post('/orders/{id}/shipment/cancel', 'Manage/ShipmentController@cancel', ['permission:orders.update']);
            $r->get('/orders/{id}/shipment/waybill', 'Manage/ShipmentController@waybill', ['permission:orders.view']);
            $r->get('/orders/{id}/shipment/awb', 'Manage/ShipmentController@awb', ['permission:orders.view']);
            $r->post('/orders/{id}/shipment/awb/printed', 'Manage/ShipmentController@awbPrinted', ['permission:orders.view']);
            $r->get('/orders/awb/bulk', 'Manage/ShipmentController@bulk', ['permission:orders.view']);
            $r->post('/orders/awb/printed', 'Manage/ShipmentController@awbPrintedAmong', ['permission:orders.view']);
        };
        $r->group('/admin', ['auth:admin'], $orders);
        $r->group('/staff', ['auth:staff'], $orders);

        // Stock Control Management: admin always passes, staff need stock.manage.
        $stock = function (Router $r): void {
            $r->get('/stock', 'Manage/StockController@index', ['permission:stock.manage']);
            $r->get('/stock/movements', 'Manage/StockController@movements', ['permission:stock.manage']);
            $r->get('/stock/history', 'Manage/StockController@history', ['permission:stock.manage']);
            $r->post('/stock/adjust', 'Manage/StockController@adjust', ['permission:stock.manage']);
        };
        $r->group('/admin', ['auth:admin'], $stock);
        $r->group('/staff', ['auth:staff'], $stock);

        // Payment gateway credentials: admin always passes, staff need payments.manage (assigned by an admin).
        $payments = function (Router $r): void {
            $r->get('/payments', 'Manage/PaymentController@show', ['permission:payments.manage']);
            $r->post('/payments', 'Manage/PaymentController@update', ['permission:payments.manage']);
            $r->get('/payments/billplz', 'Manage/BillplzController@show', ['permission:payments.manage']);
            $r->post('/payments/billplz', 'Manage/BillplzController@update', ['permission:payments.manage']);
            $r->post('/payments/billplz/test', 'Manage/BillplzController@test', ['permission:payments.manage']);
        };
        $shipping = function (Router $r): void {
            $r->get('/shipping/ninjavan', 'Manage/NinjaVanController@show', ['permission:shipping.manage']);
            $r->post('/shipping/ninjavan', 'Manage/NinjaVanController@update', ['permission:shipping.manage']);
            $r->post('/shipping/ninjavan/test', 'Manage/NinjaVanController@test', ['permission:shipping.manage']);
        };
        $r->group('/admin', ['auth:admin'], $shipping);
        $r->group('/staff', ['auth:staff'], $shipping);
        $r->group('/admin', ['auth:admin'], $payments);
        $r->group('/staff', ['auth:staff'], $payments);

        // Profile and password: the same two pages for every kind of account, each under its own portal.
        $account = function (Router $r): void {
            $r->get('/profile', 'Account/AccountController@profile');
            $r->post('/profile', 'Account/AccountController@updateProfile');
            $r->get('/password', 'Account/AccountController@password');
            $r->post('/password', 'Account/AccountController@updatePassword');
        };
        $r->group('/admin', ['auth:admin'], $account);
        $r->group('/staff', ['auth:staff'], $account);
        $r->group('/member', ['auth:member'], $account);

        // Admin portal
        $r->group('/admin', ['auth:admin'], function (Router $r): void {
            $r->get('/', 'Admin/DashboardController@index');
            $r->get('/dashboard/data', 'Admin/DashboardController@data');
            $r->get('/staff', 'Admin/StaffController@index');
            $r->get('/staff/create', 'Admin/StaffController@create');
            $r->post('/staff', 'Admin/StaffController@store');
            $r->post('/staff/{id}/toggle', 'Admin/StaffController@toggle');
            $r->get('/staff/{id}/edit', 'Admin/StaffController@edit');
            $r->post('/staff/{id}/permissions', 'Admin/StaffController@permissions');
            $r->get('/backup', 'Admin/BackupController@index');
            $r->post('/backup', 'Admin/BackupController@create');
            $r->get('/backup/download/{name}', 'Admin/BackupController@download');
            $r->post('/backup/{name}/delete', 'Admin/BackupController@delete');
            $r->post('/backup/{name}/restore', 'Admin/BackupController@restore');
            $r->get('/email', 'Admin/EmailController@show');
            $r->post('/email', 'Admin/EmailController@update');
            $r->post('/email/test', 'Admin/EmailController@test');
            $r->get('/email/announce', 'Admin/EmailController@announceForm');
            $r->post('/email/announce', 'Admin/EmailController@announce');
            $r->get('/whatsapp', 'Admin/WhatsAppController@show');
            $r->post('/whatsapp', 'Admin/WhatsAppController@update');
            $r->post('/whatsapp/test', 'Admin/WhatsAppController@test');
            $r->post('/whatsapp/check', 'Admin/WhatsAppController@check');
            $r->get('/visitors', 'Admin/VisitorsController@index');
            $r->get('/visitors/stats', 'Admin/VisitorsController@stats');
            $r->get('/settings', 'Admin/SettingsController@edit');
            $r->post('/settings', 'Admin/SettingsController@update');
            $r->post('/settings/logo', 'Admin/SettingsController@logo');
            $r->post('/settings/logo/remove', 'Admin/SettingsController@removeLogo');
            $r->get('/affiliates', 'Admin/AffiliateController@index');
            $r->get('/affiliates/{id}', 'Admin/AffiliateController@show');
            $r->post('/affiliates/{id}/payout', 'Admin/AffiliateController@payout');
            $r->get('/commissions', 'Admin/AffiliateController@commissions');
            $r->post('/commissions/{id}/approve', 'Admin/AffiliateController@approve');
            $r->post('/commissions/{id}/reject', 'Admin/AffiliateController@reject');
        });

        // Staff portal
        $r->group('/staff', ['auth:staff'], function (Router $r): void {
            $r->get('/', 'Staff/DashboardController@index');
            $r->get('/dashboard/data', 'Staff/DashboardController@data');
        });

        // Member portal
        $r->group('/member', ['auth:member'], function (Router $r): void {
            $r->get('/', 'Member/DashboardController@index');
            $r->post('/verify-email/resend', 'Auth/VerifyEmailController@resend');
            $r->get('/orders', 'Member/OrderController@index');
            $r->get('/orders/{orderNo}', 'Member/OrderController@show');
            $r->post('/orders/{orderNo}/cancel', 'Member/OrderController@cancel');
            $r->post('/orders/{orderNo}/receipt', 'Member/OrderController@receipt');
            $r->get('/orders/{orderNo}/receipt/{id}', 'Member/OrderController@receiptFile');
            $r->post('/orders/{orderNo}/pay', 'Member/OrderController@pay');
            $r->get('/affiliate', 'Member/AffiliateController@show');
            $r->get('/addresses', 'Member/AddressController@index');
            $r->post('/addresses', 'Member/AddressController@store');
            $r->get('/addresses/{id}/edit', 'Member/AddressController@edit');
            $r->post('/addresses/{id}', 'Member/AddressController@update');
            $r->post('/addresses/{id}/default', 'Member/AddressController@makeDefault');
            $r->post('/addresses/{id}/delete', 'Member/AddressController@destroy');
            $r->get('/wishlist', 'Member/WishlistController@index');
        });
    });
};
