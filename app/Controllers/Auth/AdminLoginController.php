<?php
declare(strict_types=1);

namespace App\Controllers\Auth;

final class AdminLoginController extends PortalLoginController
{
    protected string $role = 'admin';
    protected string $portalName = 'Admin';
}
