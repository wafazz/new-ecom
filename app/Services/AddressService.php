<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;

/** Member address book. Every read/write is scoped by user_id, so one member can never touch another's rows. */
final class AddressService
{
    public const MAX = 10;
    public const FIELDS = ['name', 'phone', 'line1', 'line2', 'city', 'state', 'postcode'];

    /** @param array $in name, phone, line1, line2, city, state, postcode (extra keys ignored) @return array<string,string> errors */
    public static function validate(array $in): array
    {
        $errors = (new Validator($in, [
            'name' => 'required|max:120', 'phone' => 'required|max:30', 'line1' => 'required|max:190', 'line2' => 'max:190',
            'city' => 'required|max:100', 'state' => 'required', 'postcode' => 'required',
        ]))->errors();
        if (!isset($errors['phone']) && !preg_match('/^\+?[0-9][0-9 \-]{6,}$/', (string) $in['phone'])) {
            $errors['phone'] = 'Phone must be a valid number, e.g. 012-3456789.';
        }
        if (!isset($errors['postcode']) && !preg_match('/^\d{5}$/', (string) $in['postcode'])) {
            $errors['postcode'] = 'Postcode must be 5 digits.';
        }
        if (!isset($errors['state']) && !in_array($in['state'], config('states'), true)) {
            $errors['state'] = 'Choose a state from the list.';
        }
        return $errors;
    }

    /** Trimmed address fields (plus label) from a request body. */
    public static function collect(\App\Core\Request $r): array
    {
        $in = [];
        foreach (self::FIELDS as $f) {
            $in[$f] = trim($r->str($f));
        }
        $in['label'] = trim($r->str('label'));
        return $in;
    }

    public static function forUser(int $userId): array
    {
        return Database::query('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC', [$userId])->fetchAll();
    }

    public static function find(int $userId, int $id): ?array
    {
        return Database::query('SELECT * FROM addresses WHERE id = ? AND user_id = ?', [$id, $userId])->fetch() ?: null;
    }

    public static function count(int $userId): int
    {
        return (int) Database::query('SELECT COUNT(*) FROM addresses WHERE user_id = ?', [$userId])->fetchColumn();
    }

    /** @return int|null new id, or null when the member is at the limit */
    public static function create(int $userId, array $in, bool $makeDefault = false): ?int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Database::query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]); // serialises concurrent adds
            $n = self::count($userId);
            if ($n >= self::MAX) {
                $pdo->rollBack();
                return null;
            }
            $default = $makeDefault || $n === 0;
            if ($default) {
                Database::query('UPDATE addresses SET is_default = 0 WHERE user_id = ?', [$userId]);
            }
            Database::query(
                'INSERT INTO addresses (user_id, label, name, phone, line1, line2, city, state, postcode, is_default) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$userId, ($in['label'] ?? '') ?: null, $in['name'], $in['phone'], $in['line1'], ($in['line2'] ?? '') ?: null, $in['city'], $in['state'], $in['postcode'], $default ? 1 : 0]
            );
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function update(int $userId, int $id, array $in): bool
    {
        Database::query(
            'UPDATE addresses SET label = ?, name = ?, phone = ?, line1 = ?, line2 = ?, city = ?, state = ?, postcode = ? WHERE id = ? AND user_id = ?',
            [($in['label'] ?? '') ?: null, $in['name'], $in['phone'], $in['line1'], ($in['line2'] ?? '') ?: null, $in['city'], $in['state'], $in['postcode'], $id, $userId]
        );
        return self::find($userId, $id) !== null;
    }

    public static function makeDefault(int $userId, int $id): bool
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            if (!Database::query('SELECT id FROM addresses WHERE id = ? AND user_id = ? FOR UPDATE', [$id, $userId])->fetch()) {
                $pdo->rollBack();
                return false;
            }
            Database::query('UPDATE addresses SET is_default = (id = ?) WHERE user_id = ?', [$id, $userId]);
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Deleting the default promotes the newest remaining address. */
    public static function delete(int $userId, int $id): bool
    {
        $addr = self::find($userId, $id);
        if ($addr === null) {
            return false;
        }
        Database::query('DELETE FROM addresses WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ((int) $addr['is_default'] === 1) {
            Database::query('UPDATE addresses SET is_default = 1 WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]);
        }
        return true;
    }

    /** From checkout: save the delivery address unless an identical one (line1 + postcode) exists or the book is full. */
    public static function saveFromOrder(int $userId, array $in): void
    {
        $dupe = (int) Database::query('SELECT COUNT(*) FROM addresses WHERE user_id = ? AND LOWER(line1) = LOWER(?) AND postcode = ?', [$userId, $in['line1'], $in['postcode']])->fetchColumn();
        if ($dupe === 0) {
            self::create($userId, $in);
        }
    }
}
