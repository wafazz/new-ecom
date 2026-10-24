<?php
// Signing out other sessions: a password change or reset ends every session started before it. Runs only on `*_test` databases.
use App\Core\Auth;
use App\Models\User;
use App\Services\AccountService;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();

// Auth caches the signed-in user for the request; clear that cache to act like a new request.
$reloadAuth = function (): void {
    (new ReflectionProperty(Auth::class, 'loaded'))->setValue(null, false);
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, null);
};

$id = User::create('member', 'Sesi Tester', 'sesi@example.test', 'Passw0rd!x');
// Signing a session out calls Session::start(), which warns in CLI because there are no headers to send.
// Those warnings are expected here, so they are muted only around the calls that sign out.
$signedOut = function (): ?array {
    $prev = error_reporting(E_ALL & ~E_WARNING);
    try {
        return Auth::user();
    } finally {
        error_reporting($prev);
    }
};

$_SESSION = ['auth_id' => $id, 'auth_ver' => 0];
$reloadAuth();
check('a session from before any change stays signed in', Auth::user()['id'] == $id);

User::setPassword($id, password_hash('NewPassw0rd!', PASSWORD_DEFAULT));
$reloadAuth();
check('after a password change, the other session is signed out', $signedOut() === null);

$_SESSION = ['auth_id' => $id, 'auth_ver' => (int) User::find($id)['session_version']];
$reloadAuth();
check('a session started after the change stays signed in', Auth::user()['id'] == $id);

$before = (int) User::find($id)['session_version'];
$errors = AccountService::changePassword(User::find($id), 'NewPassw0rd!', 'Another1Pass!', 'Another1Pass!');
check('changing the password from the account page succeeds', $errors === []);
check('and the counter moves on, so older sessions are ended', (int) User::find($id)['session_version'] === $before + 1);

$_SESSION = ['auth_id' => $id, 'auth_ver' => $before];
$reloadAuth();
check('a session from before that change is signed out', $signedOut() === null);
