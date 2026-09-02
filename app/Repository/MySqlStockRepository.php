<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class MySqlStockRepository implements StockRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * FOR UPDATE turns this from a snapshot read into a locking read.
     *
     * MySQL defaults to REPEATABLE READ, under which a plain SELECT inside a
     * transaction reads a consistent snapshot taken at the transaction's first
     * read — so two concurrent issues would both see the ORIGINAL balance and
     * both pass their stock check. FOR UPDATE reads the latest committed value
     * and holds an exclusive lock until commit, so the second transaction
     * blocks here and then sees the first one's result.
     *
     * This locks exactly one index record because of
     * UNIQUE (product_id, warehouse_id); without that index MySQL would scan
     * and take gap locks over a range instead.
     */
    public function readBalanceForUpdate(int $productId, int $warehouseId): int
    {
        if (!$this->pdo->inTransaction()) {
            // A lock taken outside a transaction is released immediately, which
            // would look like it worked and protect nothing.
            throw new \LogicException('readBalanceForUpdate() must be called inside a transaction.');
        }

        $statement = $this->pdo->prepare(
            'SELECT quantity FROM product_stocks
              WHERE product_id = ? AND warehouse_id = ?
              FOR UPDATE'
        );
        $statement->execute([$productId, $warehouseId]);
        $quantity = $statement->fetchColumn();

        return $quantity === false ? 0 : (int) $quantity;
    }

    public function adjust(int $productId, int $warehouseId, int $delta): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE product_stocks
                SET quantity = quantity + ?
              WHERE product_id = ? AND warehouse_id = ?'
        );
        $statement->execute([$delta, $productId, $warehouseId]);
    }

    public function exists(int $productId, int $warehouseId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM product_stocks WHERE product_id = ? AND warehouse_id = ?'
        );
        $statement->execute([$productId, $warehouseId]);

        return $statement->fetchColumn() !== false;
    }

    public function ensureRow(int $productId, int $warehouseId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO product_stocks (product_id, warehouse_id, quantity) VALUES (?, ?, 0)'
        );
        $statement->execute([$productId, $warehouseId]);
    }
}
