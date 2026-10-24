<?php
declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Env;

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}

function config(string $key, mixed $default = null): mixed
{
    return App\Core\Config::get($key, $default);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim((string) env('APP_URL', ''), '/') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
}

function money(float|int|string $amount): string
{
    return env('CURRENCY', 'MYR') . ' ' . number_format((float) $amount, 2);
}

function redirect(string $path): never
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

function old(string $key, string $default = ''): string
{
    $old = $_SESSION['_old'] ?? [];
    $v = $old[$key] ?? $default;
    return e(is_scalar($v) ? (string) $v : $default);
}

function field_error(string $key): string
{
    $err = $_SESSION['_errors'][$key] ?? null;
    return $err ? '<small class="error">' . e($err) . '</small>' : '';
}

/** Flash messages + old input are consumed once per rendered page. */
function flash_clear(): void
{
    unset($_SESSION['_old'], $_SESSION['_errors']);
}

function cart_count(): int
{
    return App\Services\CartService::count();
}

/** Inline icon from the storefront sprite (defined once in layouts/main.php). */
function icon(string $name, string $class = 'i'): string
{
    return '<svg class="' . e($class) . '" aria-hidden="true"><use href="#i-' . e($name) . '"/></svg>';
}
