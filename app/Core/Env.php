<?php
declare(strict_types=1);

namespace App\Core;

final class Env
{
    private static array $vars = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            self::$vars[trim($key)] = self::parse(trim($value));
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        // Real environment variables (server config, CI) win over the .env file.
        $real = getenv($key);
        if ($real !== false) {
            return self::parse($real);
        }
        return self::$vars[$key] ?? $default;
    }

    private static function parse(string $value): mixed
    {
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            return substr($value, 1, -1);
        }
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $value,
        };
    }
}
