<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Services\EmailService;
use App\Services\EmailSettings;
use App\Services\MailService;

/** Email settings, the two automatic email templates, and announcements to members or customers. HQ (admin) only. */
final class EmailController extends Controller
{
    public function show(Request $request): void
    {
        header('Cache-Control: no-store');
        $this->view('admin/email', [
            'title' => 'Email',
            'overview' => EmailSettings::overview(),
            'variables' => EmailSettings::VARIABLES,
            'defaults' => EmailSettings::defaults(),
            'scripts' => ['messaging.js'],
            'log' => Database::query('SELECT created_at, kind, to_email, subject, ok, error FROM email_log ORDER BY id DESC LIMIT 20')->fetchAll(),
        ], 'portal');
    }

    public function update(Request $request): void
    {
        $tpl = is_array($request->body['tpl'] ?? null) ? $request->body['tpl'] : [];
        $errors = EmailSettings::save([
            'enabled' => $request->str('enabled'),
            'key' => trim($request->str('key')),
            'from_name' => trim($request->str('from_name')),
            'from_email' => trim($request->str('from_email')),
            'reply_to' => trim($request->str('reply_to')),
            'tpl' => $tpl,
        ], (int) Auth::user()['id']);
        if ($errors !== []) {
            $this->backWithErrors('/admin/email', ['form' => implode(' ', $errors)]);
        }
        $this->success('/admin/email', 'Email settings saved.');
    }

    public function test(Request $request): void
    {
        $to = trim($request->str('to'));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->backWithErrors('/admin/email', ['form' => 'Enter a valid email address to send the test to.']);
        }
        if (!EmailService::ready()) {
            $this->backWithErrors('/admin/email', ['form' => 'Turn email on and save the API key and sender first.']);
        }
        $text = 'This is a test email from ' . config('app.name') . '. If you received it, email is connected.';
        $r = MailService::send($to, 'Test email from ' . config('app.name'), $text, 'test');
        $r['ok']
            ? $this->success('/admin/email', 'Test email sent. Check the inbox (and spam folder).')
            : $this->backWithErrors('/admin/email', ['form' => 'Test email failed: ' . $r['message']]);
    }

    public function announceForm(Request $request): void
    {
        header('Cache-Control: no-store');
        $this->view('admin/email_announce', [
            'title' => 'Announcements',
            'audiences' => EmailService::AUDIENCES,
            'counts' => array_map(fn($k) => count(EmailService::recipients($k)), array_keys(EmailService::AUDIENCES)),
            'ready' => EmailService::ready(),
            'campaigns' => Database::query('SELECT id, subject, audience, recipients, sent, failed, created_at FROM email_campaigns ORDER BY id DESC LIMIT 20')->fetchAll(),
            'scripts' => ['messaging.js'],
        ], 'portal');
    }

    public function announce(Request $request): void
    {
        $subject = trim($request->str('subject'));
        $body = trim($request->str('body'));
        if ($subject === '' || mb_strlen($subject) > 200) {
            $this->backWithErrors('/admin/email/announce', ['form' => 'Write a subject (up to 200 characters).']);
        }
        if ($body === '') {
            $this->backWithErrors('/admin/email/announce', ['form' => 'Write the message.']);
        }
        $r = EmailService::announce($subject, $body, $request->str('audience'), (int) Auth::user()['id']);
        if (is_string($r)) {
            $this->backWithErrors('/admin/email/announce', ['form' => $r]);
        }
        $this->success('/admin/email/announce', "Sent to {$r['sent']} of {$r['recipients']} people" . ($r['failed'] > 0 ? " ({$r['failed']} failed; see the log)." : '.'));
    }
}
