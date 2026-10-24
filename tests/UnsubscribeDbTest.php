<?php
// Announcement unsubscribe: signed links, opt-outs leave the audience, each announcement carries its own link. Runs only on `*_test` databases.
use App\Models\User;
use App\Services\EmailService as E;
use App\Services\EmailSettings as S;
use App\Services\MailService as M;
use App\Services\UnsubscribeService as U;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
reset_test_db();
putenv('APP_KEY=unsub-db-test-key-0123456789abcdef0123');
\App\Core\Database::query("DELETE FROM settings WHERE `key` LIKE 'email.%'");
$admin = User::create('admin', 'Un Admin', 'un-admin@example.test', 'Passw0rd!x');
User::create('member', 'Keep Me', 'keep@example.test', 'Passw0rd!x');
User::create('member', 'Leave Me', 'leave@example.test', 'Passw0rd!x');

$tpl = [];
foreach (S::KINDS as $kind) {
    $tpl[$kind] = ['on' => '1', 'subject' => S::defaults()[$kind]['subject'], 'body' => S::defaults()[$kind]['body']];
}
S::save(['enabled' => '1', 'key' => 're_UNSUBKEY1234', 'from_name' => 'Lure Shop', 'from_email' => 'shop@example.test', 'reply_to' => '', 'tpl' => $tpl], $admin);

$sent = [];
M::setTransport(function (string $m, string $path, string $key, ?string $body, array $headers) use (&$sent): array {
    $items = json_decode((string) $body, true);
    foreach ($items as $msg) {
        $sent[] = $msg;
    }
    return ['code' => 200, 'json' => ['data' => array_map(fn($i) => ['id' => "b{$i}"], range(0, count($items) - 1))]];
});

// Signed links.
$token = U::token('Keep@Example.test');
check('a link opens for the address it was made for (case does not matter)', U::emailFor($token) === 'keep@example.test');
$flipped = substr($token, 0, -1) . (substr($token, -1) === 'a' ? 'b' : 'a');
check('a link with a changed signature is refused', U::emailFor($flipped) === null);
$otherName = rtrim(strtr(base64_encode('someone@example.test'), '+/', '-_'), '=');
check('a signature for one address does not open another', U::emailFor($otherName . '.' . substr($token, strpos($token, '.') + 1)) === null);
check('garbage is not a link', U::emailFor('not-a-link') === null && U::emailFor('') === null);

// Opt-out: the address leaves the audience, the others stay.
U::unsubscribe('leave@example.test');
$audience = array_column(E::recipients('members'), 'email');
check('someone who unsubscribed is left out of the audience', !in_array('leave@example.test', $audience, true) && in_array('keep@example.test', $audience, true));

// Announcements: each one carries that person's own unsubscribe link and the mail-client headers.
$result = E::announce('Promosi', 'Hai {name}, ada promosi baru.', 'members', $admin);
\App\Services\Queue::run(); // the worker sends the queued announcement
check('the announcement goes only to people still subscribed', is_array($result) && $result['recipients'] === 1 && count($sent) === 1);
$msg = $sent[0];
check('the email tells people how to unsubscribe, with their own link', str_contains((string) $msg['text'], '/unsubscribe/' . U::token('keep@example.test')));
check('the email carries List-Unsubscribe headers for one-click unsubscribe', str_contains((string) $msg['headers']['List-Unsubscribe'], '/unsubscribe/') && $msg['headers']['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click');
check('the link in the headers opens for the same person', U::emailFor(substr((string) $msg['headers']['List-Unsubscribe'], strpos((string) $msg['headers']['List-Unsubscribe'], '/unsubscribe/') + 13, -1)) === 'keep@example.test');
