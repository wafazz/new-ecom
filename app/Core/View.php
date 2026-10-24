<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    /** Render app/Views/{view}.php inside a layout (null = no layout). */
    public static function render(string $view, array $data = [], ?string $layout = 'main'): string
    {
        $content = self::capture($view, $data);
        if ($layout === null) {
            return $content;
        }
        return self::capture("layouts/{$layout}", $data + ['content' => $content]);
    }

    private static function capture(string $view, array $data): string
    {
        $file = BASE_PATH . '/app/Views/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$view}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
