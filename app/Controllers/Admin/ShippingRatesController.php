<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Services\ShippingRates;

/** Postage rates for the two shipping zones. HQ (admin) only. */
final class ShippingRatesController extends Controller
{
    public function edit(Request $request): void
    {
        header('Cache-Control: no-store');
        $this->view('admin/shipping_rates', [
            'title' => 'Shipping rates',
            'zones' => ShippingRates::ZONES,
            'fields' => ShippingRates::FIELDS,
            'rates' => ShippingRates::overview(),
        ], 'portal');
    }

    public function update(Request $request): void
    {
        // Each zone's form posts its own zone, so saving one never touches or checks the other.
        $zone = $request->str('zone');
        if (!isset(ShippingRates::ZONES[$zone])) {
            $this->backWithErrors('/admin/shipping-rates', ['form' => 'Unknown shipping zone.']);
        }
        $fields = is_array($request->body[$zone] ?? null) ? $request->body[$zone] : [];
        $errors = ShippingRates::saveZone($zone, $fields, (int) Auth::user()['id']);
        if ($errors !== []) {
            $this->backWithErrors('/admin/shipping-rates', ['form' => ShippingRates::ZONES[$zone] . ': ' . implode(' ', $errors)]);
        }
        $this->success('/admin/shipping-rates', ShippingRates::ZONES[$zone] . ' rates saved. New orders use them straight away.');
    }
}
