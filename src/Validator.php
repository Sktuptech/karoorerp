<?php

declare(strict_types=1);

namespace Karoor\Core;

use DateTimeImmutable;
use Throwable;

final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $validated = [];

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|list<string|callable>> $rules
     * @param array<string, string> $labels
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $labels = []
    ) {
    }

    public function passes(): bool
    {
        $this->errors = [];
        $this->validated = [];

        foreach ($this->expandedRules() as $field => $fieldRules) {
            $exists = $this->hasValue($field);
            $value = $this->getValue($field);
            $rules = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;
            $ruleNames = array_map(
                static fn (mixed $rule): string => is_string($rule)
                    ? strtolower((string) strtok($rule, ':'))
                    : 'callable',
                $rules
            );

            if (in_array('sometimes', $ruleNames, true) && !$exists) {
                continue;
            }

            if (
                !$exists
                && !in_array('required', $ruleNames, true)
                && !$this->isConditionallyRequired($rules)
            ) {
                continue;
            }

            if (in_array('nullable', $ruleNames, true) && ($value === null || $value === '')) {
                $this->setValidatedValue($field, null);
                continue;
            }

            foreach ($rules as $rule) {
                if (is_callable($rule)) {
                    $message = $rule($value, $this->data, $field);
                    if (is_string($message) && $message !== '') {
                        $this->addError($field, $message);
                    }
                    continue;
                }

                [$name, $parameters] = $this->parseRule($rule);
                if (in_array($name, ['sometimes', 'nullable'], true)) {
                    continue;
                }

                $message = $this->validateRule($name, $value, $exists, $parameters, $field);
                if ($message !== null) {
                    $this->addError($field, $message);
                    if (in_array($name, ['required', 'required_if'], true)) {
                        break;
                    }
                }
            }

            if (!isset($this->errors[$field]) && $exists) {
                $this->setValidatedValue($field, $this->normalizedValue($value, $ruleNames));
            }
        }

        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function validated(): array
    {
        return $this->validated;
    }

    /** @return list<string> */
    public function errorsFor(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    public function firstError(?string $field = null): ?string
    {
        if ($field !== null) {
            return $this->errors[$field][0] ?? null;
        }

        foreach ($this->errors as $messages) {
            return $messages[0] ?? null;
        }

        return null;
    }

    /** @return array<string, string|list<string|callable>> */
    private function expandedRules(): array
    {
        $expanded = [];
        foreach ($this->rules as $field => $rules) {
            if (!str_contains($field, '.*')) {
                $expanded[$field] = $rules;
                continue;
            }

            [$prefix, $suffix] = explode('.*', $field, 2);
            $items = $this->getValue($prefix);
            if (!is_array($items)) {
                $expanded[$field] = $rules;
                continue;
            }

            foreach (array_keys($items) as $index) {
                $expanded[$prefix . '.' . $index . $suffix] = $rules;
            }
        }

        return $expanded;
    }

    /** @return array{0: string, 1: list<string>} */
    private function parseRule(string $rule): array
    {
        $parts = explode(':', trim($rule), 2);
        $parameters = isset($parts[1]) ? str_getcsv($parts[1], ',', '"', '\\') : [];
        return [strtolower($parts[0]), $parameters];
    }

    /** @param list<string> $parameters */
    private function validateRule(
        string $rule,
        mixed $value,
        bool $exists,
        array $parameters,
        string $field
    ): ?string {
        $label = $this->label($field);
        $firstParameter = $parameters[0] ?? null;

        return match ($rule) {
            'required' => (!$exists || $this->isEmpty($value)) ? "{$label} is required." : null,
            'required_if' => $this->requiredIfFails($value, $parameters) ? "{$label} is required." : null,
            'string' => !is_string($value) ? "{$label} must be text." : null,
            'integer' => filter_var($value, FILTER_VALIDATE_INT) === false ? "{$label} must be an integer." : null,
            'numeric', 'decimal' => !is_numeric($value) ? "{$label} must be a number." : null,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === null
                ? "{$label} must be true or false."
                : null,
            'array' => !is_array($value) ? "{$label} must be a list." : null,
            'list' => !is_array($value) || !array_is_list($value) ? "{$label} must be a list." : null,
            'email' => !is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false
                ? "{$label} must be a valid email address."
                : null,
            'url' => !is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false
                ? "{$label} must be a valid URL."
                : null,
            'date' => !$this->validDate($value, $firstParameter ?? 'Y-m-d')
                ? "{$label} must be a valid date."
                : null,
            'after_or_equal' => !$this->compareDate($value, $this->getValue((string) $firstParameter), '>=')
                ? "{$label} must be on or after {$this->label((string) $firstParameter)}."
                : null,
            'in' => !in_array((string) $value, $parameters, true)
                ? "{$label} contains an unsupported value."
                : null,
            'not_in' => in_array((string) $value, $parameters, true)
                ? "{$label} contains a prohibited value."
                : null,
            'min' => !$this->meetsMinimum($value, (float) $firstParameter)
                ? "{$label} must be at least {$firstParameter}."
                : null,
            'max' => !$this->meetsMaximum($value, (float) $firstParameter)
                ? "{$label} may not be greater than {$firstParameter}."
                : null,
            'min_length' => !is_string($value) || $this->length($value) < (int) $firstParameter
                ? "{$label} must contain at least {$firstParameter} characters."
                : null,
            'max_length' => !is_string($value) || $this->length($value) > (int) $firstParameter
                ? "{$label} may not contain more than {$firstParameter} characters."
                : null,
            'between' => !$this->between($value, (float) ($parameters[0] ?? 0), (float) ($parameters[1] ?? 0))
                ? "{$label} is outside the allowed range."
                : null,
            'same' => $value !== $this->getValue((string) $firstParameter)
                ? "{$label} must match {$this->label((string) $firstParameter)}."
                : null,
            'different' => $value === $this->getValue((string) $firstParameter)
                ? "{$label} must be different from {$this->label((string) $firstParameter)}."
                : null,
            'regex' => $this->regexFails($value, (string) $firstParameter)
                ? "{$label} has an invalid format."
                : null,
            'uuid' => !is_string($value) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1
                ? "{$label} must be a valid UUID."
                : null,
            'uploaded_file' => !$this->validUpload($value)
                ? "{$label} must be a successful file upload."
                : null,
            'image' => !$this->validImage($value)
                ? "{$label} must be a valid JPEG, PNG, or WebP image."
                : null,
            'extensions' => !$this->validExtension($value, $parameters)
                ? "{$label} has an unsupported file extension."
                : null,
            'mimes' => !$this->validMime($value, $parameters)
                ? "{$label} has an unsupported file type."
                : null,
            'max_file' => !$this->validUpload($value) || (int) $value['size'] > (int) $firstParameter
                ? "{$label} exceeds the maximum file size."
                : null,
            default => "{$label} has an unknown validation rule ({$rule}).",
        };
    }

    /** @param list<string> $parameters */
    private function requiredIfFails(mixed $value, array $parameters): bool
    {
        $otherField = array_shift($parameters);
        if ($otherField === null || !in_array((string) $this->getValue($otherField), $parameters, true)) {
            return false;
        }

        return $this->isEmpty($value);
    }

    /** @param list<string|callable> $rules */
    private function isConditionallyRequired(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (!is_string($rule)) {
                continue;
            }

            [$name, $parameters] = $this->parseRule($rule);
            if ($name === 'required_if' && $this->requiredIfFails(null, $parameters)) {
                return true;
            }
        }

        return false;
    }

    private function validDate(mixed $value, string $format): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format($format) === $value;
    }

    private function compareDate(mixed $left, mixed $right, string $operator): bool
    {
        try {
            $leftDate = new DateTimeImmutable((string) $left);
            $rightDate = new DateTimeImmutable((string) $right);
            return $operator === '>=' && $leftDate >= $rightDate;
        } catch (Throwable) {
            return false;
        }
    }

    private function meetsMinimum(mixed $value, float $minimum): bool
    {
        if (is_numeric($value)) {
            return (float) $value >= $minimum;
        }
        if (is_string($value)) {
            return $this->length($value) >= $minimum;
        }
        return is_array($value) && count($value) >= $minimum;
    }

    private function meetsMaximum(mixed $value, float $maximum): bool
    {
        if (is_numeric($value)) {
            return (float) $value <= $maximum;
        }
        if (is_string($value)) {
            return $this->length($value) <= $maximum;
        }
        return is_array($value) && count($value) <= $maximum;
    }

    private function between(mixed $value, float $minimum, float $maximum): bool
    {
        return $this->meetsMinimum($value, $minimum) && $this->meetsMaximum($value, $maximum);
    }

    private function regexFails(mixed $value, string $pattern): bool
    {
        if (!is_string($value) || $pattern === '') {
            return true;
        }

        set_error_handler(static fn (): bool => true);
        try {
            return preg_match($pattern, $value) !== 1;
        } finally {
            restore_error_handler();
        }
    }

    private function validUpload(mixed $value): bool
    {
        return is_array($value)
            && isset($value['tmp_name'], $value['error'], $value['size'])
            && (int) $value['error'] === UPLOAD_ERR_OK
            && is_uploaded_file((string) $value['tmp_name'])
            && (int) $value['size'] >= 0;
    }

    private function validImage(mixed $value): bool
    {
        if (!$this->validUpload($value) || !class_exists(\finfo::class)) {
            return false;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $value['tmp_name']);
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    /** @param list<string> $extensions */
    private function validExtension(mixed $value, array $extensions): bool
    {
        if (!$this->validUpload($value) || !isset($value['name'])) {
            return false;
        }

        $extension = strtolower(pathinfo((string) $value['name'], PATHINFO_EXTENSION));
        $allowed = array_map('strtolower', $extensions);
        return $extension !== '' && in_array($extension, $allowed, true);
    }

    /** @param list<string> $mimeTypes */
    private function validMime(mixed $value, array $mimeTypes): bool
    {
        if (!$this->validUpload($value) || !class_exists(\finfo::class)) {
            return false;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $value['tmp_name']);
        return is_string($mime) && in_array(strtolower($mime), array_map('strtolower', $mimeTypes), true);
    }

    private function normalizedValue(mixed $value, array $rules): mixed
    {
        if (in_array('integer', $rules, true)) {
            return (int) $value;
        }
        if (in_array('numeric', $rules, true) || in_array('decimal', $rules, true)) {
            return is_string($value) ? trim($value) : $value;
        }
        if (in_array('boolean', $rules, true)) {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }

        return is_string($value) ? trim($value) : $value;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || (is_array($value) && $value === []);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function hasValue(string $path): bool
    {
        $value = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }
        return true;
    }

    private function getValue(string $path): mixed
    {
        $value = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    private function setValidatedValue(string $path, mixed $value): void
    {
        $target = &$this->validated;
        $segments = explode('.', $path);
        foreach ($segments as $index => $segment) {
            if ($index === array_key_last($segments)) {
                $target[$segment] = $value;
                break;
            }
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }
    }

    private function label(string $field): string
    {
        if (isset($this->labels[$field])) {
            return $this->labels[$field];
        }

        $leaf = (string) preg_replace('/^.*\./', '', $field);
        return ucfirst(str_replace('_', ' ', $leaf));
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field] ??= [];
        $this->errors[$field][] = $message;
    }
}
