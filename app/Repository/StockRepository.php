<?php

declare(strict_types=1);

namespace App\Repository;

interface StockRepository
{
    /**
     * Read the balance in a way that prevents anyone else changing it until the
     * surrounding transaction ends.
     *
     * The name states the CONTRACT deliberately. A plain getBalance() that
     * silently locked would be worse: a caller could not tell that calling it
     * inside a transaction has consequences for concurrency. What is hidden is
     * the mechanism (MySQL uses SELECT ... FOR UPDATE; the fake just returns a
     * number), not the fact that exclusivity is required.
     *
     * MUST be called inside a transaction.
     */
    public function readBalanceForUpdate(int $productId, int $warehouseId): int;

    /** Applies a signed delta to an existing stock row. */
    public function adjust(int $productId, int $warehouseId, int $delta): void;

    public function exists(int $productId, int $warehouseId): bool;

    /** Creates the row at zero if it is missing, so a first receipt has somewhere to land. */
    public function ensureRow(int $productId, int $warehouseId): void;
}
