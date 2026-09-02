<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LedgerEntry;

final class InMemoryStockLedgerRepository implements StockLedgerRepository
{
    /** @var list<LedgerEntry> */
    public array $entries = [];

    private int $nextId = 1;

    public function append(LedgerEntry $entry): int
    {
        $id = $this->nextId++;

        // Every field is carried over, including the display names the MySQL
        // implementation obtains by JOIN. An earlier version dropped them, which
        // made this fake quietly lossy: code that read entry->productName worked
        // against the database and returned an empty string under test.
        $this->entries[] = new LedgerEntry(
            $id,
            $entry->productId,
            $entry->warehouseId,
            $entry->movementType,
            $entry->quantity,
            $entry->referenceType,
            $entry->referenceId,
            $entry->performedBy,
            $entry->createdAt,
            $entry->productName,
            $entry->productSku,
            $entry->warehouseName,
            $entry->performedByName,
        );

        return $id;
    }

    public function between(string $from, string $to): array
    {
        return $this->entries;
    }

    public function forProduct(int $productId, int $limit = 50): array
    {
        $matching = array_values(array_filter(
            $this->entries,
            static fn (LedgerEntry $e): bool => $e->productId === $productId,
        ));

        return array_slice($matching, 0, $limit);
    }

    public function balanceFromLedger(int $productId, int $warehouseId): int
    {
        $total = 0;
        foreach ($this->entries as $entry) {
            if ($entry->productId === $productId && $entry->warehouseId === $warehouseId) {
                $total += $entry->quantity;
            }
        }

        return $total;
    }
}
