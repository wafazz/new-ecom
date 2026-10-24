<?php
declare(strict_types=1);

namespace App\Controllers\Auth;

final class StaffLoginController extends PortalLoginController
{
    protected string $role = 'staff';
    protected string $portalName = 'Staff';
}
