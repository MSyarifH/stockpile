<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * One product's balance in one warehouse (WH-01: stock differs per location).
 */
final class StockLevel
{
    public function __construct(
        public readonly int $productId,
        public readonly int $warehouseId,
        public readonly string $warehouseName,
        public readonly int $quantity,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['product_id'],
            (int) $row['warehouse_id'],
            (string) ($row['warehouse_name'] ?? ''),
            (int) $row['quantity'],
        );
    }
}
