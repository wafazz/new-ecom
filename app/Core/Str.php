<?php
declare(strict_types=1);

namespace App\Core;

final class Str
{
    public static function slug(string $text): string
    {
        $text = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text), '-'));
        return $text !== '' ? substr($text, 0, 120) : 'item';
    }

    /** First slug not already taken in $table.$column (optionally ignoring one row id). */
    public static function uniqueSlug(string $table, string $column, string $base, ?int $ignoreId = null): string
    {
        $slug = $base;
        for ($n = 2; ; $n++) {
            $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?" . ($ignoreId ? ' AND id <> ?' : '');
            $taken = (int) Database::query($sql, $ignoreId ? [$slug, $ignoreId] : [$slug])->fetchColumn();
            if ($taken === 0) {
                return $slug;
            }
            $slug = $base . '-' . $n;
        }
    }
}
