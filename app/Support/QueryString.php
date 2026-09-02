<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds a URL that keeps the current filters and changes only what is given.
 *
 * FIND-01 requires filters to stay active when moving between pages. Holding
 * them in the query string rather than the session means a filtered list is
 * also linkable and survives the back button, and two browser tabs cannot
 * fight over one stored filter.
 */
final class QueryString
{
    /** @param array<string,mixed> $current */
    public function __construct(private readonly array $current)
    {
    }

    /** @param array<string,mixed> $changes null removes a parameter */
    public function with(array $changes): string
    {
        $parameters = $this->current;

        foreach ($changes as $key => $value) {
            if ($value === null || $value === '') {
                unset($parameters[$key]);
                continue;
            }
            $parameters[$key] = $value;
        }

        // Blank values are dropped so the URL shows only the filters in force.
        $parameters = array_filter(
            $parameters,
            static fn (mixed $value): bool => $value !== '' && $value !== null,
        );

        if ($parameters === []) {
            return '?';
        }

        return '?' . http_build_query($parameters);
    }
}
