<?php

declare(strict_types=1);

namespace App\Entity;

final class SalesOrderItem
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $productId,
        public readonly int $quantity,
        public readonly float $sellingPrice,
        public readonly ?string $productName = null,
        public readonly ?string $productSku = null,
        /** Stock in the order's source warehouse, loaded for display only. */
        public readonly ?int $availableInWarehouse = null,
    ) {
    }

    public function lineTotal(): float
    {
        return $this->quantity * $this->sellingPrice;
    }

    /**
     * Advisory only (decision D3). Stock is NOT reserved at approval; the
     * authoritative check happens inside the locked transaction at goods issue.
     * This exists so an Admin approving an order can see it will not currently
     * be fulfillable.
     */
    public function isShortOnStock(): bool
    {
        return $this->availableInWarehouse !== null && $this->availableInWarehouse < $this->quantity;
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['product_id'],
            (int) $row['quantity'],
            (float) $row['selling_price'],
            isset($row['product_name']) ? (string) $row['product_name'] : null,
            isset($row['product_sku']) ? (string) $row['product_sku'] : null,
            isset($row['available']) ? (int) $row['available'] : null,
        );
    }
}
