<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Exception\ValidationException;
use LogicException;

/**
 * Server-side validation (VAL-01). The frontend validates too, for feedback,
 * but the backend is the source of truth: a request that bypasses the browser
 * must fail here.
 *
 * Rules are expressed as "field => rule string", e.g. 'email' => 'required|email'.
 *
 * Available rules: required, optional, email, int, decimal, boolean, date,
 * in:a,b,c, min_value:n, max_value:n, max_length:n.
 *
 * An unrecognised rule name throws rather than being ignored — see the default
 * branch of applyRules().
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $valid = [];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     */
    public function __construct(private readonly array $data, private readonly array $rules)
    {
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @return array<string,mixed> the validated values
     * @throws ValidationException
     */
    public static function validate(array $data, array $rules): array
    {
        $validator = new self($data, $rules);
        if (!$validator->passes()) {
            throw new ValidationException($validator->errors());
        }
        return $validator->validated();
    }

    public function passes(): bool
    {
        $this->errors = [];
        $this->valid = [];

        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            $rules = explode('|', $ruleString);
            $optional = in_array('optional', $rules, true);

            if ($this->isBlank($value)) {
                if (in_array('required', $rules, true)) {
                    $this->errors[$field] = $this->label($field) . ' is required.';
                    continue;
                }
                if ($optional) {
                    $this->valid[$field] = null;
                }
                continue;
            }

            $this->applyRules($field, $value, $rules);
        }

        return $this->errors === [];
    }

    /** @param list<string> $rules */
    private function applyRules(string $field, mixed $value, array $rules): void
    {
        $string = is_scalar($value) ? trim((string) $value) : '';

        foreach ($rules as $rule) {
            [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

            switch ($name) {
                case 'email':
                    if (filter_var($string, FILTER_VALIDATE_EMAIL) === false) {
                        $this->fail($field, 'must be a valid email address.');
                        return;
                    }
                    break;

                case 'int':
                    if (filter_var($string, FILTER_VALIDATE_INT) === false) {
                        $this->fail($field, 'must be a whole number.');
                        return;
                    }
                    $value = (int) $string;
                    break;

                case 'decimal':
                    if (!is_numeric($string)) {
                        $this->fail($field, 'must be a number.');
                        return;
                    }
                    $value = round((float) $string, 2);
                    break;

                // min_value and max_length are named for WHAT they compare.
                // They were previously 'min' and 'max', which read as a matching
                // pair but were not one: min compared the numeric value while
                // max compared the string length. Every call site happened to
                // mean the right thing, but 'int|max:100' would have limited the
                // number of digits rather than the value — accepting 999999.
                case 'min_value':
                    if ((float) $value < (float) $parameter) {
                        $this->fail($field, sprintf('must be at least %s.', $parameter));
                        return;
                    }
                    break;

                case 'max_value':
                    if ((float) $value > (float) $parameter) {
                        $this->fail($field, sprintf('must be at most %s.', $parameter));
                        return;
                    }
                    break;

                case 'max_length':
                    if (mb_strlen($string) > (int) $parameter) {
                        $this->fail($field, sprintf('must be at most %s characters.', $parameter));
                        return;
                    }
                    break;

                case 'in':
                    $allowed = explode(',', (string) $parameter);
                    if (!in_array($string, $allowed, true)) {
                        $this->fail($field, 'is not one of the allowed values.');
                        return;
                    }
                    break;

                case 'date':
                    $parsed = date_create_immutable($string);
                    if ($parsed === false) {
                        $this->fail($field, 'must be a valid date.');
                        return;
                    }
                    break;

                case 'boolean':
                    $value = in_array($string, ['1', 'true', 'on', 'yes'], true);
                    break;

                // Handled in passes() before this method is reached; listed so
                // they do not fall into the default branch below.
                case 'required':
                case 'optional':
                    break;

                default:
                    // Without this branch a mistyped rule name was silently
                    // ignored and the value accepted unconditionally, so
                    // 'required|emial' validated anything at all. Rules are
                    // written by developers, never supplied by a request, so
                    // failing loudly here is safe and catches the typo at the
                    // first execution instead of in production.
                    throw new LogicException(sprintf(
                        'Unknown validation rule "%s" for field "%s".',
                        (string) $name,
                        $field,
                    ));
            }
        }

        $this->valid[$field] = is_string($value) ? trim($value) : $value;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function fail(string $field, string $message): void
    {
        $this->errors[$field] = $this->label($field) . ' ' . $message;
    }

    private function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string,mixed> */
    public function validated(): array
    {
        return $this->valid;
    }
}
