<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Setting;
use App\Models\User;

/**
 * First-run setup. The wizard is open only on a new install (no admin yet, or the lock file is missing), and only
 * with the INSTALL_TOKEN from .env. Once it finishes it writes the lock file, and then it is closed for good.
 */
final class Installer
{
    public static function lockPath(): string
    {
        return (string) env('INSTALL_LOCK', BASE_PATH . '/storage/installed.lock');
    }

    /** True once a lock file exists or an admin account already exists (a live shop is never re-installed). */
    public static function installed(): bool
    {
        if (is_file(self::lockPath())) {
            return true;
        }
        try {
            return (int) Database::query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
        } catch (\Throwable) {
            return false; // no database yet: the wizard is needed
        }
    }

    public static function tokenConfigured(): bool
    {
        return strlen((string) env('INSTALL_TOKEN', '')) >= 16;
    }

    public static function tokenMatches(string $given): bool
    {
        $token = (string) env('INSTALL_TOKEN', '');
        return strlen($token) >= 16 && hash_equals($token, $given);
    }

    /** @return array<int,array{label:string, ok:bool, required:bool, detail:string}> */
    public static function checks(): array
    {
        $dbOk = false;
        $dbDetail = '';
        try {
            Database::query('SELECT 1')->fetch();
            $dbOk = true;
            $dbDetail = 'Connected to ' . (string) env('DB_NAME', '');
        } catch (\Throwable $e) {
            $dbDetail = 'Could not connect. Check DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASS in .env.';
        }
        $pending = $dbOk ? Migrator::pending() : [];
        $key = (string) env('APP_KEY', '');
        $writable = fn(string $p) => is_dir($p) && is_writable($p);

        return [
            self::check('PHP 8.2 or newer', PHP_VERSION_ID >= 80200, true, 'Running PHP ' . PHP_VERSION),
            self::check('Extension pdo_mysql', extension_loaded('pdo_mysql'), true, 'Needed for the database'),
            self::check('Extension curl', extension_loaded('curl'), true, 'Needed for NinjaVan, WhatsApp and email'),
            self::check('Extension openssl', extension_loaded('openssl'), true, 'Needed for encrypted settings'),
            self::check('Extension fileinfo', extension_loaded('fileinfo'), true, 'Needed to check uploaded files'),
            self::check('Extension mbstring', extension_loaded('mbstring'), true, 'Needed for text handling'),
            self::check('Extension zlib (optional)', extension_loaded('zlib'), false, extension_loaded('zlib') ? 'Backups will be compressed' : 'Backups will be saved uncompressed'),
            self::check('Extension zip (optional)', class_exists('ZipArchive'), false, class_exists('ZipArchive') ? 'Backups will include the uploaded images' : 'Backups will include the database only'),
            self::check('.env file', is_file(BASE_PATH . '/.env'), true, is_file(BASE_PATH . '/.env') ? 'Found' : 'Copy .env.example to .env and fill it in'),
            self::check('APP_KEY is set', strlen($key) >= 32 && !str_starts_with($key, 'change-me'), true, 'Use a random string of at least 32 characters'),
            self::check('INSTALL_TOKEN is set', self::tokenConfigured(), true, 'Set a random INSTALL_TOKEN (16+ characters) in .env for this setup'),
            self::check('Database connection', $dbOk, true, $dbDetail),
            self::check('Database tables', $dbOk, true, $dbOk ? (count($pending) === 0 ? 'Up to date' : count($pending) . ' migration(s) will run now') : 'Waiting for the database'),
            self::check('storage/ is writable', $writable(BASE_PATH . '/storage'), true, 'Logs, receipts and backups are stored here'),
            self::check('public/uploads/ is writable', $writable(BASE_PATH . '/public/uploads'), true, 'Product and brand images are stored here'),
        ];
    }

    /** @param array<int,array{label:string, ok:bool, required:bool, detail:string}> $checks */
    public static function passed(array $checks): bool
    {
        foreach ($checks as $c) {
            if ($c['required'] && !$c['ok']) {
                return false;
            }
        }
        return true;
    }

    /**
     * Create the first admin, the shop defaults, and the lock file.
     * @return string|null error message, null on success
     */
    public static function finish(array $in): ?string
    {
        if (self::installed()) {
            return 'This shop is already set up.';
        }
        $name = trim((string) ($in['admin_name'] ?? ''));
        $email = strtolower(trim((string) ($in['admin_email'] ?? '')));
        $password = (string) ($in['admin_password'] ?? '');
        $fee = trim((string) ($in['shipping_flat'] ?? '0'));
        $commission = trim((string) ($in['commission_rate'] ?? '10'));
        if ($name === '' || mb_strlen($name) > 120) {
            return 'Enter the admin name.';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Enter a valid admin email address.';
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            return 'The admin password must be 8 to 72 characters.';
        }
        if ($password !== (string) ($in['admin_password_confirm'] ?? '')) {
            return 'The two passwords do not match.';
        }
        if (!PricingService::isMoney($fee)) {
            return 'Shipping fee must be an amount like 8.00.';
        }
        if (!AffiliateService::isRate($commission)) {
            return 'Commission rate must be 0 to 100, for example 10.';
        }

        $id = User::create('admin', $name, $email, $password);
        Setting::set('shipping_flat', PricingService::format(PricingService::cents($fee)));
        Setting::set('commission_rate', PricingService::format(PricingService::cents($commission)));
        Setting::set('guest_checkout_enabled', ($in['guest_checkout'] ?? '') === '1' ? '1' : '0');
        AuditService::log($id, 'install.finished', 'first admin created, shop defaults saved');

        $dir = dirname(self::lockPath());
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents(self::lockPath(), 'installed ' . date('c') . "\n", LOCK_EX);
        return null;
    }

    private static function check(string $label, bool $ok, bool $required, string $detail): array
    {
        return ['label' => $label, 'ok' => $ok, 'required' => $required, 'detail' => $detail];
    }
}
