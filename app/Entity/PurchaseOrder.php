<?php

declare(strict_types=1);

namespace App\Entity;

final class PurchaseOrder
{
    /** @param list<PurchaseOrderItem> $items */
    public function __construct(
        public readonly ?int $id,
        public readonly string $poNumber,
        public readonly int $supplierId,
        public readonly int $warehouseId,
        public readonly PurchaseOrderStatus $status,
        public readonly string $orderDate,
        public readonly int $createdBy,
        public readonly array $items = [],
        public readonly ?string $supplierName = null,
        public readonly ?string $warehouseName = null,
        public readonly ?string $createdByName = null,
    ) {
    }

    public function total(): float
    {
        return array_sum(array_map(
            static fn (PurchaseOrderItem $item): float => $item->lineTotal(),
            $this->items,
        ));
    }

    /**
     * Status implied by what has actually been received, rather than a flag set
     * by hand. Derived from the lines so the two can never disagree.
     */
    public function statusFromReceipts(): PurchaseOrderStatus
    {
        if ($this->items === []) {
            return $this->status;
        }

        $received = 0;
        $ordered = 0;
        foreach ($this->items as $item) {
            $received += $item->receivedQuantity;
            $ordered += $item->quantity;
        }

        if ($received === 0) {
            return PurchaseOrderStatus::Ordered;
        }

        return $received >= $ordered
            ? PurchaseOrderStatus::Received
            : PurchaseOrderStatus::PartiallyReceived;
    }

    public function hasOutstandingItems(): bool
    {
        foreach ($this->items as $item) {
            if (!$item->isFullyReceived()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<PurchaseOrderItem> $items
     */
    public static function fromRow(array $row, array $items = []): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['po_number'],
            (int) $row['supplier_id'],
            (int) $row['warehouse_id'],
            PurchaseOrderStatus::from((string) $row['status']),
            (string) $row['order_date'],
            (int) $row['created_by'],
            $items,
            isset($row['supplier_name']) ? (string) $row['supplier_name'] : null,
            isset($row['warehouse_name']) ? (string) $row['warehouse_name'] : null,
            isset($row['created_by_name']) ? (string) $row['created_by_name'] : null,
        );
    }
}
