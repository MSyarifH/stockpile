<?php

declare(strict_types=1);

namespace App\Entity;

final class PurchaseOrderItem
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $productId,
        public readonly int $quantity,
        public readonly int $receivedQuantity,
        public readonly float $purchasePrice,
        public readonly ?string $productName = null,
        public readonly ?string $productSku = null,
    ) {
    }

    /** How much of this line is still outstanding (PO-01: partial receipt). */
    public function outstanding(): int
    {
        return max(0, $this->quantity - $this->receivedQuantity);
    }

    public function isFullyReceived(): bool
    {
        return $this->outstanding() === 0;
    }

    public function lineTotal(): float
    {
        return $this->quantity * $this->purchasePrice;
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['product_id'],
            (int) $row['quantity'],
            (int) $row['received_quantity'],
            (float) $row['purchase_price'],
            isset($row['product_name']) ? (string) $row['product_name'] : null,
            isset($row['product_sku']) ? (string) $row['product_sku'] : null,
        );
    }
}
