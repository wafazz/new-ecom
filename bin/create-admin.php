<?php
declare(strict_types=1);

// Usage: php bin/create-admin.php "Name" email@example.com   (password is prompted, never in argv/history)
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Models\User;

[, $name, $email] = $argv + [null, null, null];
if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/create-admin.php \"Name\" email@example.com\n");
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

User::create('admin', $name, $email, $password);
echo "Admin created: {$email}\n";
