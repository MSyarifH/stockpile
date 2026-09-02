<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Warehouse;
use PDO;

final class WarehouseRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<Warehouse> */
    public function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT id, name, location, is_active FROM warehouses';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name';

        $statement = $this->pdo->query($sql);
        $rows = $statement === false ? [] : $statement->fetchAll();

        return array_map(static fn (array $row): Warehouse => Warehouse::fromRow($row), $rows);
    }

    public function findById(int $id): ?Warehouse
    {
        $statement = $this->pdo->prepare('SELECT id, name, location, is_active FROM warehouses WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return is_array($row) ? Warehouse::fromRow($row) : null;
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM warehouses WHERE name = ?';
        $parameters = [$name];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $parameters[] = $exceptId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    public function create(Warehouse $warehouse): int
    {
        $statement = $this->pdo->prepare('INSERT INTO warehouses (name, location, is_active) VALUES (?, ?, ?)');
        $statement->execute([$warehouse->name, $warehouse->location, $warehouse->isActive ? 1 : 0]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, Warehouse $warehouse): void
    {
        $statement = $this->pdo->prepare('UPDATE warehouses SET name = ?, location = ?, is_active = ? WHERE id = ?');
        $statement->execute([$warehouse->name, $warehouse->location, $warehouse->isActive ? 1 : 0, $id]);
    }

    public function setActive(int $id, bool $isActive): void
    {
        $statement = $this->pdo->prepare('UPDATE warehouses SET is_active = ? WHERE id = ?');
        $statement->execute([$isActive ? 1 : 0, $id]);
    }

    /**
     * Every product needs a stock row in every warehouse (WH-01). Creating a
     * warehouse therefore backfills rows at zero rather than leaving gaps that
     * every later query would have to treat as "missing means zero".
     */
    public function createStockRowsForAllProducts(int $warehouseId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO product_stocks (product_id, warehouse_id, quantity)
             SELECT p.id, ?, 0 FROM products p
             WHERE NOT EXISTS (
                 SELECT 1 FROM product_stocks ps WHERE ps.product_id = p.id AND ps.warehouse_id = ?
             )'
        );
        $statement->execute([$warehouseId, $warehouseId]);
    }
}
