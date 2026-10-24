<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\Backup;

/** Database and file backups for HQ. Downloads are private and logged. */
final class BackupController extends Controller
{
    public function index(Request $request): void
    {
        header('Cache-Control: no-store');
        $this->view('admin/backup', [
            'title' => 'Backup',
            'backups' => Backup::list(),
            'canZip' => class_exists(\ZipArchive::class),
            'compressed' => extension_loaded('zlib'),
        ], 'portal');
    }

    public function create(Request $request): void
    {
        try {
            $r = Backup::create($request->str('with_files') === '1');
        } catch (\Throwable $e) {
            $this->backWithErrors('/admin/backup', ['form' => 'The backup could not be made: ' . $e->getMessage()]);
        }
        AuditService::log((int) Auth::user()['id'], 'backup.create', $r['db'] . ($r['files'] ? ' + ' . $r['files'] : ''));
        $this->success('/admin/backup', 'Backup saved: ' . $r['db'] . ($r['files'] ? ' and ' . $r['files'] : '') . '.');
    }

    public function download(Request $request, string $name): void
    {
        $file = Backup::path($name) ?? Response::abort(404);
        AuditService::log((int) Auth::user()['id'], 'backup.download', $name);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    public function delete(Request $request, string $name): void
    {
        if (!Backup::delete($name)) {
            Response::abort(404);
        }
        AuditService::log((int) Auth::user()['id'], 'backup.delete', $name);
        $this->success('/admin/backup', 'Backup deleted.');
    }
}
