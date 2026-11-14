<?php
declare(strict_types=1);

// Usage: php bin/create-admin.php "Name" email@example.com   (password is prompted, never in argv/history)
//        php bin/create-admin.php --superadmin "Name" email@example.com   (also switches modules on and off)
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Models\User;

$superadmin = in_array('--superadmin', $argv, true);
$args = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--superadmin'));
[$name, $email] = $args + [null, null];
if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/create-admin.php [--superadmin] \"Name\" email@example.com\n");
    exit(1);
}
if (User::findByEmail($email)) {
    fwrite(STDERR, "That email already exists.\n");
    exit(1);
}

$password = getenv('ADMIN_PASSWORD') ?: null;
if ($password === null) {
    fwrite(STDOUT, 'Password (min 8, hidden): ');
    system('stty -echo');
    $password = trim((string) fgets(STDIN));
    system('stty echo');
    fwrite(STDOUT, "\n");
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$role = $superadmin ? 'superadmin' : 'admin';
User::create($role, $name, $email, $password);
echo ($superadmin ? 'Superadmin' : 'Admin') . " created: {$email}\n";
