<?php
declare(strict_types=1);

namespace App\Core;

abstract class Model
{
    protected static string $table;
    protected static string $primaryKey = 'id';

    public static function find(int $id): ?array
    {
        $sql = sprintf('SELECT * FROM `%s` WHERE `%s` = ? LIMIT 1', static::$table, static::$primaryKey);
        return Database::query($sql, [$id])->fetch() ?: null;
    }

    public static function all(): array
    {
        return Database::query(sprintf('SELECT * FROM `%s`', static::$table))->fetchAll();
    }

    public static function insert(array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            static::$table,
            implode(',', array_map(fn($c) => "`{$c}`", $cols)),
            implode(',', array_fill(0, count($cols), '?'))
        );
        Database::query($sql, array_values($data));
        return (int) Database::connection()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $set = implode(',', array_map(fn($c) => "`{$c}` = ?", array_keys($data)));
        $sql = sprintf('UPDATE `%s` SET %s WHERE `%s` = ?', static::$table, $set, static::$primaryKey);
        Database::query($sql, [...array_values($data), $id]);
    }

    public static function delete(int $id): void
    {
        Database::query(sprintf('DELETE FROM `%s` WHERE `%s` = ?', static::$table, static::$primaryKey), [$id]);
    }
}
