<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/** What any signed-in person (admin, staff or member) may change about their own account. Each method returns errors keyed by field ([] = done). */
final class AccountService
{
    /** @return array<string,string> */
    public static function updateProfile(int $userId, string $name, string $phone): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $phone = trim($phone);
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Please enter your name.';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'] = 'Your name is too long (120 characters max).';
        }
        if ($phone !== '' && (!preg_match('/^[0-9+()\-\s]{6,30}$/', $phone) || preg_match_all('/\d/', $phone) < 6)) {
            $errors['phone'] = 'Enter a phone number like +60 12-345 6789 (digits, spaces, + - and brackets).';
        }
        if ($errors === []) {
            User::update($userId, ['name' => $name, 'phone' => $phone === '' ? null : $phone]);
        }
        return $errors;
    }

    /** @param array $user the signed-in users row (needs id, email, password_hash) @return array<string,string> */
    public static function changePassword(array $user, string $current, string $new, string $confirm): array
    {
        $errors = [];
        if (!password_verify($current, (string) $user['password_hash'])) {
            $errors['current_password'] = 'Your current password is not right.';
        }
        if (strlen($new) < 8) {
            $errors['password'] = 'Use at least 8 characters.';
        } elseif (strlen($new) > 72) {
            $errors['password'] = 'Use 72 characters or fewer.';
        } elseif (!isset($errors['current_password']) && hash_equals($current, $new)) {
            $errors['password'] = 'Choose a password you have not used just now.';
        } elseif (strtolower($new) === strtolower((string) $user['email'])) {
            $errors['password'] = 'Your password cannot be your email address.';
        }
        if (!isset($errors['password']) && $new !== $confirm) {
            $errors['password_confirmation'] = 'The two passwords do not match.';
        }
        if ($errors === []) {
            User::update((int) $user['id'], ['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);
        }
        return $errors;
    }
}
