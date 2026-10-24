<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $files = [];

    /** Dot access: config('database.host') reads config/database.php['host']. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $file = array_shift($parts);

        if (!isset(self::$files[$file])) {
            $path = BASE_PATH . "/config/{$file}.php";
            self::$files[$file] = is_file($path) ? require $path : [];
        }

        $value = self::$files[$file];
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
