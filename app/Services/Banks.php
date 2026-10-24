<?php
declare(strict_types=1);

namespace App\Services;

/** The list of Malaysian banks from config/banks.php. */
final class Banks
{
    /** @return array<string,string[]> group => bank names */
    public static function groups(): array
    {
        return (array) config('banks');
    }

    /** @return string[] */
    public static function all(): array
    {
        return array_merge(...array_values(self::groups()));
    }

    public static function known(string $name): bool
    {
        return in_array($name, self::all(), true);
    }
}
