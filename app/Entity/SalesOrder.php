<?php

declare(strict_types=1);

namespace App\Entity;

final class SalesOrder
{
    /** @param list<SalesOrderItem> $items */
    public function __construct(
        public readonly ?int $id,
        public readonly string $soNumber,
        public readonly int $customerId,
        public readonly int $warehouseId,
        public readonly SalesOrderStatus $status,
        public readonly string $orderDate,
        public readonly int $createdBy,
        public readonly ?int $approvedBy = null,
        public readonly ?string $approvedAt = null,
        public readonly array $items = [],
        public readonly ?string $customerName = null,
        public readonly ?string $warehouseName = null,
        public readonly ?string $createdByName = null,
        public readonly ?string $approvedByName = null,
    ) {
    }

    public function total(): float
    {
        return array_sum(array_map(
            static fn (SalesOrderItem $item): float => $item->lineTotal(),
            $this->items,
        ));
    }

    public function isOwnedBy(AuthenticatedUser $user): bool
    {
        return $this->createdBy === $user->id;
    }

    /**
     * The segregation-of-duties rule, expressed once (§1.2, decision D2).
     *
     * TWO independent conditions: the actor must hold approval authority, and
     * must not be the person who raised the order. Reading §1.2's table alone
     * would give only the first, which leaves the self-approval loophole open
     * on the most privileged role.
     */
    public function canBeApprovedBy(AuthenticatedUser $actor): bool
    {
        return $actor->role === Role::Admin && $actor->id !== $this->createdBy;
    }

    /** Advisory (D3): any line currently short in the source warehouse. */
    public function hasLinesShortOnStock(): bool
    {
        foreach ($this->items as $item) {
            if ($item->isShortOnStock()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<SalesOrderItem> $items
     */
    public static function fromRow(array $row, array $items = []): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['so_number'],
            (int) $row['customer_id'],
            (int) $row['warehouse_id'],
            SalesOrderStatus::from((string) $row['status']),
            (string) $row['order_date'],
            (int) $row['created_by'],
            isset($row['approved_by']) ? (int) $row['approved_by'] : null,
            isset($row['approved_at']) ? (string) $row['approved_at'] : null,
            $items,
            isset($row['customer_name']) ? (string) $row['customer_name'] : null,
            isset($row['warehouse_name']) ? (string) $row['warehouse_name'] : null,
            isset($row['created_by_name']) ? (string) $row['created_by_name'] : null,
            isset($row['approved_by_name']) ? (string) $row['approved_by_name'] : null,
        );
    }
}
