<?php
declare(strict_types=1);

namespace App\Controllers\Auth;

final class MemberLoginController extends PortalLoginController
{
    protected string $role = 'member';
    protected string $portalName = 'Member';
}
