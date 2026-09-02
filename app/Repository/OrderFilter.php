<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Shared search/filter/sort criteria for purchase and sales orders (FIND-01).
 *
 * One class for both because the brief asks for the same three things on each:
 * search by number or counterparty, filter by status, sort by date. The status
 * VALUE differs between the two, so it stays a string here and is validated
 * against the right enum by each service.
 */
final class OrderFilter
{
    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(
        public readonly string $search = '',
        public readonly ?string $status = null,
        public readonly string $sortDirection = self::SORT_DESC,
    ) {
    }

    /**
     * Never interpolate a user-supplied value into SQL. This maps the input to
     * one of exactly two literals, so the ORDER BY clause is always safe.
     */
    public function sqlDirection(): string
    {
        return $this->sortDirection === self::SORT_ASC ? 'ASC' : 'DESC';
    }

    public function isActive(): bool
    {
        return $this->search !== '' || $this->status !== null;
    }
}
