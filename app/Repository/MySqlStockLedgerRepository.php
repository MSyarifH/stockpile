<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LedgerEntry;
use PDO;

final class MySqlStockLedgerRepository implements StockLedgerRepository
{
    private const SELECT = '
        SELECT l.id, l.product_id, l.warehouse_id, l.movement_type, l.quantity,
               l.reference_type, l.reference_id, l.performed_by, l.created_at,
               p.name AS product_name, p.sku AS product_sku,
               w.name AS warehouse_name, u.name AS performed_by_name
          FROM stock_ledger l
          JOIN products p ON p.id = l.product_id
          JOIN warehouses w ON w.id = l.warehouse_id
          JOIN users u ON u.id = l.performed_by';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function append(LedgerEntry $entry): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO stock_ledger
                 (product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $entry->productId,
            $entry->warehouseId,
            $entry->movementType->value,
            $entry->quantity,
            $entry->referenceType->value,
            $entry->referenceId,
            $entry->performedBy,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function between(string $from, string $to): array
    {
        // BETWEEN on DATE boundaries would exclude same-day movements after
        // midnight, so the upper bound is the end of the closing day.
        $statement = $this->pdo->prepare(
            self::SELECT . ' WHERE l.created_at >= ? AND l.created_at < DATE_ADD(?, INTERVAL 1 DAY)
                             ORDER BY l.created_at DESC, l.id DESC'
        );
        $statement->execute([$from, $to]);

        return array_map(
            static fn (array $row): LedgerEntry => LedgerEntry::fromRow($row),
            $statement->fetchAll(),
        );
    }

    public function forProduct(int $productId, int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            self::SELECT . ' WHERE l.product_id = ? ORDER BY l.created_at DESC, l.id DESC LIMIT ?'
        );
        $statement->bindValue(1, $productId, PDO::PARAM_INT);
        // LIMIT will not accept a string parameter once emulated prepares are off,
        // so it must be bound explicitly as an integer.
        $statement->bindValue(2, $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): LedgerEntry => LedgerEntry::fromRow($row),
            $statement->fetchAll(),
        );
    }

    public function balanceFromLedger(int $productId, int $warehouseId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(quantity), 0) FROM stock_ledger WHERE product_id = ? AND warehouse_id = ?'
        );
        $statement->execute([$productId, $warehouseId]);

        return (int) $statement->fetchColumn();
    }
}
