<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Validator;
use App\Core\Auth;
use App\Models\User;
use App\Services\AuditService;

final class StaffController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('admin/staff/index', ['title' => 'Staff', 'staff' => User::byRole('staff')], 'portal');
    }

    public function create(Request $request): void
    {
        $this->view('admin/staff/create', ['title' => 'Add staff', 'permissions' => config('permissions')], 'portal');
    }

    public function store(Request $request): void
    {
        $input = [
            'name' => trim($request->str('name')),
            'email' => trim($request->str('email')),
            'phone' => trim($request->str('phone')),
        ];
        $password = $request->str('password');
        // Only accept permission keys that exist in config.
        $allowed = array_keys(config('permissions'));
        $granted = array_values(array_intersect($allowed, array_filter((array) $request->input('permissions', []), 'is_string')));

        $v = new Validator($request->body, [
            'name' => 'required|max:120',
            'email' => 'required|email|max:190',
            'phone' => 'max:30',
            'password' => 'required|min:8|max:72',
        ]);
        $errors = $v->errors();
        if (!isset($errors['email']) && User::findByEmail($input['email']) !== null) {
            $errors['email'] = 'That email is already in use.';
        }
        if ($errors !== []) {
            $this->backWithErrors('/admin/staff/create', $errors, $input);
        }

        User::create('staff', $input['name'], $input['email'], $password, $input['phone'], $granted);
        $this->success('/admin/staff', 'Staff account created.');
    }

    public function edit(Request $request, string $id): void
    {
        $user = $this->staff($id);
        $this->view('admin/staff/edit', [
            'title' => 'Permissions: ' . $user['name'],
            'staff' => $user,
            'permissions' => config('permissions'),
            'granted' => json_decode((string) $user['permissions'], true) ?: [],
        ], 'portal');
    }

    public function permissions(Request $request, string $id): void
    {
        $user = $this->staff($id);
        $allowed = array_keys(config('permissions'));
        $granted = array_values(array_intersect($allowed, array_filter((array) $request->input('permissions', []), 'is_string')));
        $before = json_decode((string) $user['permissions'], true) ?: [];
        User::update((int) $user['id'], ['permissions' => json_encode($granted)]);
        $added = array_diff($granted, $before);
        $removed = array_diff($before, $granted);
        AuditService::log((int) Auth::user()['id'], 'staff.permissions', "{$user['email']}: +" . (implode(',', $added) ?: 'none') . ' -' . (implode(',', $removed) ?: 'none'));
        $this->success('/admin/staff', "Permissions updated for {$user['name']}.");
    }

    private function staff(string $id): array
    {
        $user = User::find((int) $id);
        return $user !== null && $user['role'] === 'staff' ? $user : \App\Core\Response::abort(404);
    }

    public function toggle(Request $request, string $id): void
    {
        $user = User::find((int) $id);
        if ($user === null || $user['role'] !== 'staff') {
            \App\Core\Response::abort(404);
        }
        $next = $user['status'] === 'active' ? 'disabled' : 'active';
        User::update((int) $id, ['status' => $next]);
        $this->success('/admin/staff', "{$user['name']} is now {$next}.");
    }
}
