<?php

declare(strict_types=1);

namespace App\Service\Exception;

use RuntimeException;

/**
 * Raised inside the stock transaction, which is what causes the rollback.
 *
 * Carries the numbers so the user is told what is actually possible rather than
 * a bare "not enough stock".
 */
final class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly int $productId,
        public readonly int $warehouseId,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(sprintf(
            'Insufficient stock: %d requested but only %d available for product %d in warehouse %d.',
            $requested,
            $available,
            $productId,
            $warehouseId,
        ));
    }
}
