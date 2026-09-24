<?php

declare(strict_types=1);

namespace App\Validation;

final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    public function validate(array $data, array $rules): bool
    {
        $this->errors = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;
            foreach ($this->normalizeRules($fieldRules) as [$rule, $parameter]) {
                $this->applyRule((string) $field, $value, $rule, $parameter);
            }
        }

        return $this->errors === [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    private function normalizeRules(array|string $rules): array
    {
        $items = is_array($rules) ? $rules : explode('|', $rules);

        return array_map(static function (string $item): array {
            [$name, $parameter] = array_pad(explode(':', $item, 2), 2, null);
            return [$name, $parameter];
        }, $items);
    }

    private function applyRule(string $field, mixed $value, string $rule, ?string $parameter): void
    {
        $empty = $value === null || $value === '';
        if ($empty && $rule !== 'required') {
            return;
        }

        $valid = match ($rule) {
            'required' => !$empty,
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'string' => is_string($value),
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'min' => $this->length($value) >= (int) $parameter,
            'max' => $this->length($value) <= (int) $parameter,
            default => throw new \InvalidArgumentException('Unknown validation rule: ' . $rule),
        };

        if (!$valid) {
            $this->errors[$field][] = $this->message($field, $rule, $parameter);
        }
    }

    private function length(mixed $value): int
    {
        if (is_string($value)) {
            return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        }

        return is_array($value) ? count($value) : 0;
    }

    private function message(string $field, string $rule, ?string $parameter): string
    {
        return match ($rule) {
            'required' => "The {$field} field is required.",
            'email' => "The {$field} field must be a valid email.",
            'string' => "The {$field} field must be text.",
            'integer' => "The {$field} field must be an integer.",
            'min' => "The {$field} field must contain at least {$parameter} characters.",
            'max' => "The {$field} field may not contain more than {$parameter} characters.",
            default => "The {$field} field is invalid.",
        };
    }
}
