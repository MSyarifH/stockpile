<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderStatus;
use App\Support\Page;

interface PurchaseOrderRepository
{
    /** @return list<PurchaseOrder> without items, for list pages */
    public function all(): array;

    /** @return Page<PurchaseOrder> */
    public function paginate(OrderFilter $filter, int $page): Page;

    /** With items loaded. */
    public function findById(int $id): ?PurchaseOrder;

    public function nextNumber(): string;

    /** Inserts the order and its lines; returns the new id. */
    public function create(PurchaseOrder $order): int;

    public function updateStatus(int $id, PurchaseOrderStatus $status): void;

    /** Adds to the received quantity of one line. */
    public function addReceivedQuantity(int $itemId, int $quantity): void;
}
