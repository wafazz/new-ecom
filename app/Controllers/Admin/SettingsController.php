<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Models\Setting;
use App\Services\AffiliateService;
use App\Services\PricingService;
use App\Services\SiteLogo;
use App\Services\UploadService;

final class SettingsController extends Controller
{
    public function edit(Request $request): void
    {
        $this->view('admin/settings', [
            'title' => 'Settings',
            'guest' => Setting::guestCheckoutEnabled(),
            'hold' => \App\Services\CartService::holdMinutes(),
            'rate' => AffiliateService::globalRate(),
            'logo' => SiteLogo::url(),
            'scripts' => ['logo-upload.js'],
        ], 'portal');
    }

    /** HQ uploads the shop logo (the route is admin-only). */
    public function logo(Request $request): void
    {
        $files = UploadService::normalise($_FILES['logo'] ?? null);
        if ($files === []) {
            $this->backWithErrors('/admin/settings', ['logo' => 'Choose an image to upload.']);
        }
        try {
            SiteLogo::save($files[0], (int) Auth::user()['id']);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->backWithErrors('/admin/settings', ['logo' => $e->getMessage()]);
        }
        $this->success('/admin/settings', 'Logo updated.');
    }

    public function removeLogo(Request $request): void
    {
        SiteLogo::remove((int) Auth::user()['id']);
        $this->success('/admin/settings', 'Logo removed. The first letter of the shop name is shown again.');
    }

    public function update(Request $request): void
    {
        $rate = trim($request->str('commission_rate'));
        if (!AffiliateService::isRate($rate)) {
            $this->backWithErrors('/admin/settings', ['commission_rate' => 'Commission rate must be 0-100, e.g. 10 or 7.5.'], $request->body);
        }
        $hold = trim($request->str('cart_hold_minutes'));
        if (!preg_match('/^\d{1,3}$/', $hold) || (int) $hold < \App\Services\CartService::HOLD_MIN || (int) $hold > \App\Services\CartService::HOLD_MAX) {
            $this->backWithErrors('/admin/settings', ['cart_hold_minutes' => 'Cart hold must be a whole number of minutes, ' . \App\Services\CartService::HOLD_MIN . '-' . \App\Services\CartService::HOLD_MAX . '.'], $request->body);
        }
        Setting::set('commission_rate', PricingService::format(PricingService::cents($rate)));
        Setting::set('guest_checkout_enabled', $request->str('guest_checkout_enabled') === '1' ? '1' : '0');
        Setting::set('cart_hold_minutes', $hold);
        $this->success('/admin/settings', 'Settings saved.');
    }
}
