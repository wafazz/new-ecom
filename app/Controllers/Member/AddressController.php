<?php
declare(strict_types=1);

namespace App\Controllers\Member;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AddressService;

final class AddressController extends Controller
{
    public function index(Request $request): void
    {
        $addresses = AddressService::forUser($this->uid());
        $this->view('member/addresses/index', [
            'title' => 'Addresses',
            'addresses' => $addresses,
            'states' => config('states'),
            'full' => count($addresses) >= AddressService::MAX,
            'max' => AddressService::MAX,
        ], 'portal');
    }

    public function store(Request $request): void
    {
        $in = AddressService::collect($request);
        $errors = $this->errors($in);
        if ($errors !== []) {
            $this->backWithErrors('/member/addresses', $errors, $in);
        }
        if (AddressService::create($this->uid(), $in, $request->str('is_default') === '1') === null) {
            $this->backWithErrors('/member/addresses', ['form' => 'You can save up to ' . AddressService::MAX . ' addresses. Delete one first.'], $in);
        }
        $this->success('/member/addresses', 'Address saved.');
    }

    public function edit(Request $request, string $id): void
    {
        $address = AddressService::find($this->uid(), (int) $id) ?? Response::abort(404);
        $this->view('member/addresses/edit', ['title' => 'Edit address', 'a' => $address, 'states' => config('states')], 'portal');
    }

    public function update(Request $request, string $id): void
    {
        $address = AddressService::find($this->uid(), (int) $id) ?? Response::abort(404);
        $in = AddressService::collect($request);
        $errors = $this->errors($in);
        if ($errors !== []) {
            $this->backWithErrors("/member/addresses/{$address['id']}/edit", $errors, $in);
        }
        AddressService::update($this->uid(), (int) $address['id'], $in);
        $this->success('/member/addresses', 'Address updated.');
    }

    public function makeDefault(Request $request, string $id): void
    {
        AddressService::makeDefault($this->uid(), (int) $id) ?: Response::abort(404);
        $this->success('/member/addresses', 'Default address updated.');
    }

    public function destroy(Request $request, string $id): void
    {
        AddressService::delete($this->uid(), (int) $id) ?: Response::abort(404);
        $this->success('/member/addresses', 'Address deleted.');
    }

    private function errors(array $in): array
    {
        $errors = AddressService::validate($in);
        if (mb_strlen($in['label']) > 40) {
            $errors['label'] = 'Label must be at most 40 characters.';
        }
        return $errors;
    }

    private function uid(): int
    {
        return (int) Auth::user()['id'];
    }
}
