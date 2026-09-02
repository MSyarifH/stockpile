<?php

declare(strict_types=1);

namespace App\Entity;

final class Product
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $sku,
        public readonly string $name,
        public readonly int $categoryId,
        public readonly string $unit,
        public readonly float $purchasePrice,
        public readonly float $sellingPrice,
        public readonly int $reorderPoint,
        public readonly ?string $imagePath,
        public readonly bool $isActive,
        public readonly ?string $categoryName = null,
        public readonly int $totalStock = 0,
    ) {
    }

    /**
     * A product is "low stock" when the total across all warehouses has fallen to
     * or below its reorder point. Expressed here, on the entity, so the dashboard,
     * the product list and the scheduled job cannot disagree about the definition.
     */
    public function isLowStock(): bool
    {
        return $this->totalStock <= $this->reorderPoint;
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['sku'],
            (string) $row['name'],
            (int) $row['category_id'],
            (string) $row['unit'],
            (float) $row['purchase_price'],
            (float) $row['selling_price'],
            (int) $row['reorder_point'],
            isset($row['image_path']) ? (string) $row['image_path'] : null,
            (bool) $row['is_active'],
            isset($row['category_name']) ? (string) $row['category_name'] : null,
            isset($row['total_stock']) ? (int) $row['total_stock'] : 0,
        );
    }
}
