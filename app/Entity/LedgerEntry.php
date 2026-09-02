<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * One row of the append-only stock history. Never updated, never deleted:
 * a correction is a new Adjustment entry.
 */
final class LedgerEntry
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $productId,
        public readonly int $warehouseId,
        public readonly MovementType $movementType,
        /** Signed: positive for Receipt, negative for Issue. */
        public readonly int $quantity,
        public readonly ReferenceType $referenceType,
        public readonly ?int $referenceId,
        public readonly int $performedBy,
        public readonly ?string $createdAt = null,
        public readonly ?string $productName = null,
        public readonly ?string $productSku = null,
        public readonly ?string $warehouseName = null,
        public readonly ?string $performedByName = null,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['product_id'],
            (int) $row['warehouse_id'],
            MovementType::from((string) $row['movement_type']),
            (int) $row['quantity'],
            ReferenceType::from((string) $row['reference_type']),
            isset($row['reference_id']) ? (int) $row['reference_id'] : null,
            (int) $row['performed_by'],
            isset($row['created_at']) ? (string) $row['created_at'] : null,
            isset($row['product_name']) ? (string) $row['product_name'] : null,
            isset($row['product_sku']) ? (string) $row['product_sku'] : null,
            isset($row['warehouse_name']) ? (string) $row['warehouse_name'] : null,
            isset($row['performed_by_name']) ? (string) $row['performed_by_name'] : null,
        );
    }
}
