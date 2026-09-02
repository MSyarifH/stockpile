<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SalesOrder;
use App\Entity\SalesOrderStatus;

interface SalesOrderRepository
{
    /**
     * @param int|null $createdBy when given, only that user's orders (§1.2:
     *                            Sales sees only its own). Filtering in the
     *                            query, not in PHP, so another user's order is
     *                            never loaded in the first place.
     * @return list<SalesOrder>
     */
    public function all(?int $createdBy = null): array;

    public function findById(int $id): ?SalesOrder;

    public function nextNumber(): string;

    public function create(SalesOrder $order): int;

    public function updateStatus(int $id, SalesOrderStatus $status): void;

    /** Records both the new status and who approved it, together. */
    public function markApproved(int $id, int $approvedBy): void;
}
