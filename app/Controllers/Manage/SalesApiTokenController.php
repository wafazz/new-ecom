<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Auth;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\SalesApiTokenService;

/**
 * Admin-only page to create and revoke the tokens CodexLure uses for /api/v1/sales-summary.
 * Not linked from the menu; reached by typing /admin/sales-api-tokens.
 */
final class SalesApiTokenController extends ManageController
{
    public function index(Request $request): void
    {
        $this->render();
    }

    public function create(Request $request): void
    {
        $label = trim($request->str('label'));
        $label = $label === '' ? 'CodexLure dashboard' : mb_substr($label, 0, 100);
        $adminId = (int) Auth::user()['id'];

        $made = SalesApiTokenService::create($label, $adminId);
        AuditService::log($adminId, 'sales_api_token.create', 'token #' . $made['id'] . ' "' . $label . '"');

        // The plain token is shown in this response only; it is not stored anywhere.
        $this->render(['newToken' => $made['token'], 'newLabel' => $label]);
    }

    public function revoke(Request $request, string $id): void
    {
        $adminId = (int) Auth::user()['id'];
        if (SalesApiTokenService::revoke((int) $id)) {
            AuditService::log($adminId, 'sales_api_token.revoke', 'token #' . (int) $id);
        }
        $this->success($this->base() . '/sales-api-tokens', 'Token revoked. Any dashboard using it will stop working.');
    }

    /** @param array<string, mixed> $extra */
    private function render(array $extra = []): void
    {
        $this->view('manage/sales-api-tokens/index', $extra + [
            'title' => 'Sales API tokens',
            'base' => $this->base(),
            'tokens' => SalesApiTokenService::list(),
            'endpoint' => url('/api/v1/sales-summary'),
            'scripts' => ['copy-link.js'],
        ], 'portal');
    }
}
