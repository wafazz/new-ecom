<?php
declare(strict_types=1);

namespace App\Core;

final class Validator
{
    private array $errors = [];

    /** Rules: required|email|min:n|max:n|numeric|int — pipe separated. */
    public function __construct(private array $data, array $rules)
    {
        foreach ($rules as $field => $ruleString) {
            $raw = $data[$field] ?? '';
            $value = is_scalar($raw) ? trim((string) $raw) : '';
            foreach (explode('|', $ruleString) as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                if ($name !== 'required' && $value === '') {
                    continue;
                }
                $msg = match ($name) {
                    'required' => $value === '' ? 'is required' : null,
                    'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'must be a valid email',
                    'min' => mb_strlen($value) < (int) $arg ? "must be at least {$arg} characters" : null,
                    'max' => mb_strlen($value) > (int) $arg ? "must be at most {$arg} characters" : null,
                    'numeric' => is_numeric($value) ? null : 'must be a number',
                    'int' => filter_var($value, FILTER_VALIDATE_INT) !== false ? null : 'must be a whole number',
                    default => null,
                };
                if ($msg !== null) {
                    $this->errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' ' . $msg;
                    break;
                }
            }
        }
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
