<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LedgerEntry;

interface StockLedgerRepository
{
    public function append(LedgerEntry $entry): int;

    /**
     * @return list<LedgerEntry> movements in a date range, newest first (REPORT-01)
     */
    public function between(string $from, string $to): array;

    /** @return list<LedgerEntry> */
    public function forProduct(int $productId, int $limit = 50): array;

    /** Sum of signed quantities — must equal product_stocks.quantity. */
    public function balanceFromLedger(int $productId, int $warehouseId): int;
}
