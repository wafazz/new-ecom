<?php
// Forgot password: emailed one-time links, cooldown, silent for unknown or disabled accounts, and the reset rules. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\User;
use App\Services\EmailSettings as S;
use App\Services\MailService as M;
use App\Services\PasswordResetService as P;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
putenv('APP_KEY=reset-db-test-key-0123456789abcdef0123');
DB::query("DELETE FROM settings WHERE `key` LIKE 'email.%'");
$admin = User::create('admin', 'Rst Admin', 'rst-admin@example.test', 'Passw0rd!x');
$member = User::create('member', 'Ahmad Rizal', 'ahmad@example.test', 'OldPassw0rd!');
$calls = [];
M::setTransport(function (string $m, string $path, string $key, ?string $body, array $headers) use (&$calls): array {
    $calls[] = $body;
    return ['code' => 200, 'json' => ['id' => 're-reset']];
});

$tpl = [];
foreach (S::KINDS as $kind) {
    $tpl[$kind] = ['on' => '1', 'subject' => S::defaults()[$kind]['subject'], 'body' => S::defaults()[$kind]['body']];
}
S::save(['enabled' => '1', 'key' => 're_RESETKEY5555', 'from_name' => 'Lure Shop', 'from_email' => 'shop@example.test', 'reply_to' => '', 'tpl' => $tpl], $admin);

// Requests: the same answer for everyone, one email per account, and a cooldown.
check('an unknown email sends nothing and stores nothing', P::request('nobody@example.test', '127.0.0.1') === null && $calls === [] && (int) DB::query('SELECT COUNT(*) FROM password_resets')->fetchColumn() === 0);
P::request('AHMAD@example.test', '127.0.0.1');
check('a member asking for a reset gets one email (the address matches in any case)', count($calls) === 1);
$body = json_decode((string) $calls[0], true);
preg_match('#/reset-password/([a-f0-9]{64})#', (string) $body['text'], $tm);
$token = $tm[1] ?? '';
check('the email carries a 64-character reset link', strlen($token) === 64);
check('the link is stored hashed, never as the token itself', (int) DB::query('SELECT COUNT(*) FROM password_resets WHERE token_hash = ?', [$token])->fetchColumn() === 0 && (int) DB::query('SELECT COUNT(*) FROM password_resets WHERE token_hash = ?', [hash('sha256', $token)])->fetchColumn() === 1);
check('the reset email is logged as kind reset', (string) DB::query("SELECT kind FROM email_log ORDER BY id DESC LIMIT 1")->fetchColumn() === 'reset');
check('the link is found for its account', (P::findByToken($token)['id'] ?? null) == $member);
P::request('ahmad@example.test', '127.0.0.1');
check('asking again inside the cooldown sends nothing new', count($calls) === 1);

$off = User::create('member', 'Disabled Person', 'off@example.test', 'Passw0rd!x');
DB::query("UPDATE users SET status = 'disabled' WHERE id = ?", [$off]);
P::request('off@example.test', '127.0.0.1');
check('a disabled account gets no reset email', count($calls) === 1);

// Choosing a new password: every rule is checked and the link survives a refused attempt.
check('a password under 8 characters is refused and the link still works', isset(P::reset($token, 'short', 'short')['password']) && P::findByToken($token) !== null);
check('a password equal to the email address is refused', isset(P::reset($token, 'ahmad@example.test', 'ahmad@example.test')['password']));
check('a confirmation that does not match is refused', isset(P::reset($token, 'NewPassw0rd!', 'Different1!')['password_confirmation']));
check('the current password cannot be chosen again', isset(P::reset($token, 'OldPassw0rd!', 'OldPassw0rd!')['password']));
check('a malformed token is not a link', P::findByToken('not-a-token') === null && isset(P::reset('not-a-token', 'NewPassw0rd!', 'NewPassw0rd!')['form']));

// A good reset: the password changes, the link is used up, sign-in throttling clears, the address is confirmed.
DB::query('UPDATE users SET email_verified_at = NULL WHERE id = ?', [$member]);
DB::query('INSERT INTO login_attempts (email, ip) VALUES (?, ?)', ['ahmad@example.test', '127.0.0.1']);
check('a valid new password is saved', P::reset($token, 'NewPassw0rd!', 'NewPassw0rd!') === []);
$row = DB::query('SELECT password_hash, email_verified_at FROM users WHERE id = ?', [$member])->fetch();
check('the new password works and the old one does not', password_verify('NewPassw0rd!', $row['password_hash']) && !password_verify('OldPassw0rd!', $row['password_hash']));
check('the address is confirmed by the reset (the link proves the inbox)', $row['email_verified_at'] !== null);
check('the same link cannot be used twice', P::findByToken($token) === null && isset(P::reset($token, 'Another1Pass!', 'Another1Pass!')['form']));
check('no reset links are left for the account', (int) DB::query('SELECT COUNT(*) FROM password_resets WHERE user_id = ?', [$member])->fetchColumn() === 0);
check('the failed sign-in attempts for the account are cleared', (int) DB::query('SELECT COUNT(*) FROM login_attempts WHERE email = ?', ['ahmad@example.test'])->fetchColumn() === 0);
check('the reset is written to the audit log', (int) DB::query("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.password_reset' AND actor_id = ?", [$member])->fetchColumn() === 1);

// Expiry.
$expired = bin2hex(random_bytes(32));
DB::query('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() - INTERVAL 1 HOUR)', [$member, hash('sha256', $expired)]);
check('an expired link is not accepted', P::findByToken($expired) === null && isset(P::reset($expired, 'NewPassw0rd!', 'NewPassw0rd!')['form']));
