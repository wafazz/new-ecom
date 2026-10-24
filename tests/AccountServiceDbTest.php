<?php
// What a signed-in person may change about their own profile and password. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\User;
use App\Services\AccountService as A;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
$id = User::create('member', 'Mia Member', 'mia@example.test', 'OldPassw0rd!', '0123456789');
$row = fn() => User::find($id);
$name = fn() => $row()['name'];

check('profile: a valid name and phone are saved (extra spaces collapsed)', A::updateProfile($id, "  Mia   Lee  ", '+60 12-345 6789') === [] && $name() === 'Mia Lee' && $row()['phone'] === '+60 12-345 6789');
check('profile: a blank phone clears it', A::updateProfile($id, 'Mia Lee', '  ') === [] && $row()['phone'] === null);
check('profile: an empty name is refused and nothing changes', isset(A::updateProfile($id, '   ', '')['name']) && $name() === 'Mia Lee');
check('profile: an over-long name is refused', isset(A::updateProfile($id, str_repeat('x', 121), '')['name']) && $name() === 'Mia Lee');
check('profile: junk phone numbers are refused (letters, too short, too long, markup)', isset(A::updateProfile($id, 'Mia', 'call me')['phone']) && isset(A::updateProfile($id, 'Mia', '123')['phone']) && isset(A::updateProfile($id, 'Mia', str_repeat('1', 31))['phone']) && isset(A::updateProfile($id, 'Mia', '<script>1234567')['phone']) && $name() === 'Mia Lee');
check('profile: a refused phone leaves the name unchanged too (all or nothing)', isset(A::updateProfile($id, 'Changed Name', 'abc')['phone']) && $name() === 'Mia Lee');
check('profile: the email, role and status can never be changed through this', (function () use ($id, $row) { $before = $row(); A::updateProfile($id, 'Mia Lee', ''); $after = $row(); return $before['email'] === $after['email'] && $before['role'] === $after['role'] && $before['status'] === $after['status']; })());
check('profile: names are stored as text (markup is escaped on display, not stripped here)', A::updateProfile($id, '<b>Mia</b>', '') === [] && $name() === '<b>Mia</b>' && A::updateProfile($id, 'Mia Lee', '') === []);

$u = fn() => User::find($id);
$cp = fn(string $cur, string $new, ?string $conf = null) => A::changePassword($u(), $cur, $new, $conf ?? $new);
check('password: a wrong current password is refused and the hash is untouched', isset($cp('nope', 'BrandNew#123')['current_password']) && password_verify('OldPassw0rd!', $u()['password_hash']));
check('password: too short (under 8) is refused', isset($cp('OldPassw0rd!', 'short1')['password']) && password_verify('OldPassw0rd!', $u()['password_hash']));
check('password: more than 72 characters is refused (bcrypt would silently cut it)', isset($cp('OldPassw0rd!', str_repeat('a', 73))['password']));
check('password: the same as the current one is refused', isset($cp('OldPassw0rd!', 'OldPassw0rd!')['password']));
check('password: the email address is refused', isset($cp('OldPassw0rd!', 'mia@example.test')['password']) && isset($cp('OldPassw0rd!', 'MIA@example.test')['password']));
check('password: a mismatched confirmation is refused', isset($cp('OldPassw0rd!', 'BrandNew#123', 'BrandNew#124')['password_confirmation']) && password_verify('OldPassw0rd!', $u()['password_hash']));
check('password: several mistakes are all reported together', count($cp('nope', 'x', 'y')) >= 2);
check('password: a good change saves a new hash; the old password stops working', $cp('OldPassw0rd!', 'BrandNew#123') === [] && password_verify('BrandNew#123', $u()['password_hash']) && !password_verify('OldPassw0rd!', $u()['password_hash']) && $u()['password_hash'] !== 'BrandNew#123');
check('password: 72 characters is the longest allowed and works', $cp('BrandNew#123', str_repeat('Ab1!', 18)) === [] && password_verify(str_repeat('Ab1!', 18), $u()['password_hash']));
check('password: spaces and unicode are allowed as-is (no trimming)', $cp(str_repeat('Ab1!', 18), '  pässwörd with spaces  ') === [] && password_verify('  pässwörd with spaces  ', $u()['password_hash']));
