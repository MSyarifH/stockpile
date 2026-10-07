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

    /**
     * @param list<string> $rules
     *
     * Split into three small pieces rather than one switch. The previous single
     * method mixed three jobs -- parsing the rule, deciding whether the value
     * passes, and coercing its type -- which is why its cognitive complexity
     * reached 29. Each rule now contributes one short arm to a match instead of
     * a branch nested inside a loop inside a switch.
     */
    private function applyRules(string $field, mixed $value, array $rules): void
    {
        $string = is_scalar($value) ? trim((string) $value) : '';

        foreach ($rules as $rule) {
            [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);
            $name = (string) $name;

            $failure = $this->violation($name, $parameter, $string, $value, $field);
            if ($failure !== null) {
                $this->fail($field, $failure);
                return;
            }

            // Coercion happens AFTER the check, so a later rule sees the typed
            // value: 'int|min_value:1' compares numbers, not strings.
            $value = $this->coerce($name, $string, $value);
        }

        $this->valid[$field] = is_string($value) ? trim($value) : $value;
    }

    /**
     * The message for a rule the value breaks, or null when it passes.
     *
     * min_value and max_length are named for WHAT they compare. They were
     * previously 'min' and 'max', which read as a matching pair but were not
     * one: min compared the numeric value while max compared the string length.
     * Every call site happened to mean the right thing, but 'int|max:100' would
     * have limited the number of digits rather than the value -- accepting
     * 999999.
     */
    private function violation(
        string $name,
        ?string $parameter,
        string $string,
        mixed $value,
        string $field,
    ): ?string {
        return match ($name) {
            'email' => filter_var($string, FILTER_VALIDATE_EMAIL) === false
                ? 'must be a valid email address.'
                : null,
            'int' => filter_var($string, FILTER_VALIDATE_INT) === false
                ? 'must be a whole number.'
                : null,
            'decimal' => !is_numeric($string)
                ? 'must be a number.'
                : null,
            'min_value' => (float) $value < (float) $parameter
                ? sprintf('must be at least %s.', $parameter)
                : null,
            'max_value' => (float) $value > (float) $parameter
                ? sprintf('must be at most %s.', $parameter)
                : null,
            'max_length' => mb_strlen($string) > (int) $parameter
                ? sprintf('must be at most %s characters.', $parameter)
                : null,
            'in' => !in_array($string, explode(',', (string) $parameter), true)
                ? 'is not one of the allowed values.'
                : null,
            'date' => date_create_immutable($string) === false
                ? 'must be a valid date.'
                : null,
            // Checked in passes() before this method is reached; listed so they
            // do not fall into the default arm below.
            'boolean', 'required', 'optional' => null,
            // Without this arm a mistyped rule name was silently ignored and the
            // value accepted unconditionally, so 'required|emial' validated
            // anything at all. Rules are written by developers, never supplied
            // by a request, so failing loudly here is safe and catches the typo
            // on first execution instead of in production.
            default => throw new LogicException(sprintf(
                'Unknown validation rule "%s" for field "%s".',
                $name,
                $field,
            )),
        };
    }

    /** The typed value a rule produces; everything else passes straight through. */
    private function coerce(string $name, string $string, mixed $value): mixed
    {
        return match ($name) {
            'int' => (int) $string,
            'decimal' => round((float) $string, 2),
            'boolean' => in_array($string, ['1', 'true', 'on', 'yes'], true),
            default => $value,
        };
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function fail(string $field, string $message): void
    {
        $this->errors[$field] = $this->label($field) . ' ' . $message;
    }

    /**
     * Turns a field name into the words a user reads in an error message.
     *
     * Three rules, no per-field table. A lookup table mapping every field to a
     * caption would have to be maintained here AND mirrored in
     * public/assets/form-validate.js, which produces the same messages in the
     * browser; two tables for one concept is exactly the duplication that
     * design avoids. These rules are small enough to implement identically on
     * both sides, and they are what made the difference between "Category id is
     * required." and "Category is required.".
     *
     * A foreign key's label drops the "_id": the user chose a *category*, and
     * has no idea the form posts its id.
     */
    private function label(string $field): string
    {
        $words = str_replace('_', ' ', $field);
        $words = preg_replace('/ id$/', '', $words) ?? $words;

        // "SKU" is an acronym everywhere in this domain; "Sku" reads as a typo.
        if ($words === 'sku') {
            return 'SKU';
        }

        return ucfirst($words);
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
