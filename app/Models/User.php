<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class User extends Model
{
    protected static string $table = 'users';

    public static function findByEmail(string $email): ?array
    {
        return Database::query('SELECT * FROM users WHERE email = ? LIMIT 1', [strtolower($email)])->fetch() ?: null;
    }

    public static function create(string $role, string $name, string $email, string $password, ?string $phone = null, ?array $permissions = null): int
    {
        return self::insert([
            'role' => $role,
            'name' => $name,
            'email' => strtolower($email),
            'phone' => $phone ?: null,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'permissions' => $permissions === null ? null : json_encode(array_values($permissions)),
        ]);
    }

    public static function byRole(string $role): array
    {
        return Database::query('SELECT id, name, email, phone, status, permissions, last_login_at, created_at FROM users WHERE role = ? ORDER BY id DESC', [$role])->fetchAll();
    }
}
