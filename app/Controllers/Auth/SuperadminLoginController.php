<?php
declare(strict_types=1);

namespace App\Controllers\Auth;

final class SuperadminLoginController extends PortalLoginController
{
    protected string $role = 'superadmin';
    protected string $portalName = 'Superadmin';
}
