<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Services\NewsletterService;

/** The newsletter list for admins: confirmed addresses only, and a CSV download of them. */
final class NewsletterController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('admin/newsletter', [
            'title' => 'Newsletter',
            'counts' => NewsletterService::counts(),
            'rows' => NewsletterService::activeRows(),
        ], 'portal');
    }

    public function export(Request $request): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="newsletter-subscribers.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['email', 'confirmed_at']);
        foreach (NewsletterService::activeRows() as $r) {
            fputcsv($out, [$r['email'], $r['confirmed_at']]);
        }
        fclose($out);
        exit;
    }
}
