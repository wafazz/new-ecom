<?php
// Shop logo: none by default, only real uploads are accepted, the URL follows the setting, removing clears it. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\Setting;
use App\Services\SiteLogo;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
DB::query("INSERT INTO users (role, name, email, password_hash, status) VALUES ('admin', 'Logo Admin', 'logo@example.test', 'x', 'active')");
$actor = (int) DB::connection()->lastInsertId();

check('no logo until HQ uploads one', SiteLogo::url() === null && SiteLogo::remove($actor) === false);
$refused = false;
try {
    SiteLogo::save(['error' => UPLOAD_ERR_OK, 'size' => 10, 'tmp_name' => '/nonexistent/fake.png', 'name' => 'fake.png'], $actor);
} catch (\InvalidArgumentException) {
    $refused = true;
}
check('a file that was not uploaded is refused and nothing is saved', $refused && SiteLogo::url() === null);
$refused = false;
try {
    SiteLogo::save(['error' => UPLOAD_ERR_INI_SIZE, 'size' => 0, 'tmp_name' => '', 'name' => 'big.png'], $actor);
} catch (\InvalidArgumentException $e) {
    $refused = str_contains($e->getMessage(), '2 MB');
}
check('an oversized upload is refused with the size message', $refused);

Setting::set('site.logo', 'uploads/branding/' . str_repeat('a', 32) . '.png');
check('the logo URL follows the saved setting', str_ends_with((string) SiteLogo::url(), '/uploads/branding/' . str_repeat('a', 32) . '.png'));
check('remove clears the logo and is logged', SiteLogo::remove($actor) === true && SiteLogo::url() === null && (int) DB::query("SELECT COUNT(*) FROM audit_logs WHERE action = 'site.logo'")->fetchColumn() === 1);
