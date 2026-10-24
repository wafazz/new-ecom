<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;

/** The shop logo shown in the admin portal, the sign-in pages and the storefront header. HQ (admin) sets it; no logo means the letter mark. */
final class SiteLogo
{
    private const KEY = 'site.logo';

    /** Public URL of the logo, or null while none is set. */
    public static function url(): ?string
    {
        $path = Setting::get(self::KEY, '');
        return $path === '' ? null : url('/' . $path);
    }

    /** The logo's file on disk (for documents such as invoices), or null while none is set. */
    public static function file(): ?string
    {
        $path = Setting::get(self::KEY, '');
        return $path === '' ? null : BASE_PATH . '/public/' . $path;
    }

    /**
     * Store a new logo (JPG, PNG or WebP, max 2 MB) and remove the one it replaces.
     *
     * @throws \InvalidArgumentException with a user-safe message
     */
    public static function save(array $file, int $actorId): void
    {
        $old = Setting::get(self::KEY, '');
        $new = UploadService::saveImage($file, 'branding');
        Setting::set(self::KEY, $new);
        if ($old !== '') {
            UploadService::delete($old);
        }
        AuditService::log($actorId, 'site.logo', 'logo replaced');
    }

    public static function remove(int $actorId): bool
    {
        $old = Setting::get(self::KEY, '');
        if ($old === '') {
            return false;
        }
        Setting::set(self::KEY, '');
        UploadService::delete($old);
        AuditService::log($actorId, 'site.logo', 'logo removed');
        return true;
    }
}
