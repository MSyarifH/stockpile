<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Search and filter criteria for the product list (FIND-01).
 *
 * A value object rather than a handful of nullable parameters: the repository,
 * the service and the view all need the same set, and passing four nullables in
 * a fixed order is the kind of signature that gets mis-called silently.
 */
final class ProductFilter
{
    public const STOCK_LOW = 'low';
    public const STOCK_NORMAL = 'normal';

    public function __construct(
        public readonly string $search = '',
        public readonly ?int $categoryId = null,
        /** 'low', 'normal' or null for any */
        public readonly ?string $stockStatus = null,
        public readonly bool $activeOnly = false,
    ) {
    }

    public function isActive(): bool
    {
        return $this->search !== ''
            || $this->categoryId !== null
            || $this->stockStatus !== null;
    }
}
