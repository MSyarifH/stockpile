<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One page of results plus the numbers a pager needs.
 *
 * FIND-01 fixes the page size at 10. It is a constant here rather than a
 * parameter each caller passes, so three list pages cannot drift apart.
 *
 * @template T
 */
final class Page
{
    public const PER_PAGE = 10;

    /** @param list<T> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $currentPage,
        public readonly int $perPage = self::PER_PAGE,
    ) {
    }

    public function totalPages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasPrevious(): bool
    {
        return $this->currentPage > 1;
    }

    public function hasNext(): bool
    {
        return $this->currentPage < $this->totalPages();
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** 1-based index of the first row shown, for "showing 11–20 of 34". */
    public function from(): int
    {
        return $this->total === 0 ? 0 : (($this->currentPage - 1) * $this->perPage) + 1;
    }

    public function to(): int
    {
        return min($this->currentPage * $this->perPage, $this->total);
    }

    /** Clamps a user-supplied page number; ?page=-5 must not produce a negative OFFSET. */
    public static function normalisePage(int $requested): int
    {
        return max(1, $requested);
    }

    public function offset(): int
    {
        return ($this->currentPage - 1) * $this->perPage;
    }

    public static function offsetFor(int $page, int $perPage = self::PER_PAGE): int
    {
        return (self::normalisePage($page) - 1) * $perPage;
    }
}
