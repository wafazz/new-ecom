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
        $in = [];
        foreach (array_keys(ShippingRates::ZONES) as $zone) {
            $in[$zone] = is_array($request->body[$zone] ?? null) ? $request->body[$zone] : [];
        }
        $errors = ShippingRates::save($in, (int) Auth::user()['id']);
        if ($errors !== []) {
            $this->backWithErrors('/admin/shipping-rates', ['form' => implode(' ', $errors)]);
        }
        $this->success('/admin/shipping-rates', 'Shipping rates saved. New orders use them straight away.');
    }
}
