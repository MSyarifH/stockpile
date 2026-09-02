<?php

declare(strict_types=1);

namespace App\Entity;

use App\Support\Exception\ValidationException;

/**
 * One requested stock movement.
 *
 * A named object rather than four loose ints. The alternative signature,
 * issue(int $productId, int $warehouseId, int $quantity, int $performedBy),
 * is four parameters of the same type in a row: swapping any two compiles,
 * passes static analysis, and silently moves the wrong stock. Naming them
 * removes a whole class of mistake that no test would reliably catch.
 *
 * Quantity is always POSITIVE here. The direction comes from MovementType, so a
 * caller cannot accidentally issue a negative amount and increase stock.
 */
final class MovementCommand
{
    public function __construct(
        public readonly int $productId,
        public readonly int $warehouseId,
        public readonly int $quantity,
        public readonly int $performedBy,
        public readonly ReferenceType $referenceType = ReferenceType::Manual,
        public readonly ?int $referenceId = null,
    ) {
        if ($quantity <= 0) {
            throw new ValidationException(['quantity' => 'Quantity must be greater than zero.']);
        }
    }

    /**
     * Identifies the stock row this command touches. Used to group several
     * lines of the same order that hit the same row, and to order lock
     * acquisition deterministically.
     */
    public function stockKey(): string
    {
        return $this->productId . ':' . $this->warehouseId;
    }
}
