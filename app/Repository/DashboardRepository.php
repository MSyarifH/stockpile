<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Aggregation queries for the dashboards (DASH-01).
 *
 * DASH-01 requires every figure to come from an aggregation query rather than a
 * stored or hardcoded number, so each method below is one SQL statement that
 * computes its answer from the transactional tables.
 *
 * Concrete class, no interface: nothing branches on these numbers, so no unit
 * test needs to fake them (ADR-001's criterion). Their correctness is a property
 * of the SQL, which an integration test exercises directly.
 */
final class DashboardRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Inventory valued at PURCHASE price, i.e. at cost (decision D7).
     * Selling price would report unrealised margin as if it were an asset.
     *
     * Counts stock of DEACTIVATED products too, deliberately: a discontinued
     * line still physically occupies the warehouse and is still an asset. That
     * differs from productsBelowReorderPoint(), which excludes them because a
     * discontinued line is not reordered. The two figures therefore cover
     * different populations on purpose, and the dashboard labels say so
     * (decision D10).
     */
    public function inventoryValueAtCost(): float
    {
        $statement = $this->pdo->query(
            'SELECT COALESCE(SUM(ps.quantity * p.purchase_price), 0)
               FROM product_stocks ps
               JOIN products p ON p.id = ps.product_id'
        );

        return $statement === false ? 0.0 : (float) $statement->fetchColumn();
    }

    public function totalUnitsInStock(): int
    {
        $statement = $this->pdo->query('SELECT COALESCE(SUM(quantity), 0) FROM product_stocks');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /**
     * Counted with the same definition the product list and the scheduled job
     * use: total across all warehouses at or below the reorder point.
     */
    public function productsBelowReorderPoint(): int
    {
        $statement = $this->pdo->query(
            'SELECT COUNT(*) FROM (
                 SELECT p.id
                   FROM products p
                   LEFT JOIN product_stocks ps ON ps.product_id = p.id
                  WHERE p.is_active = 1
                  GROUP BY p.id, p.reorder_point
                 HAVING COALESCE(SUM(ps.quantity), 0) <= p.reorder_point
             ) low'
        );

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    public function activeProductCount(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /**
     * @return array<string,int> status => count, for every status that exists
     */
    public function purchaseOrdersByStatus(): array
    {
        $statement = $this->pdo->query(
            'SELECT status, COUNT(*) AS total FROM purchase_orders GROUP BY status'
        );

        return $this->asStatusMap($statement === false ? [] : $statement->fetchAll());
    }

    /**
     * @param int|null $createdBy restricts to one seller's orders (§1.2)
     * @return array<string,int>
     */
    public function salesOrdersByStatus(?int $createdBy = null): array
    {
        $sql = 'SELECT status, COUNT(*) AS total FROM sales_orders';
        $parameters = [];

        if ($createdBy !== null) {
            $sql .= ' WHERE created_by = ?';
            $parameters[] = $createdBy;
        }
        $sql .= ' GROUP BY status';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $this->asStatusMap($statement->fetchAll());
    }

    /** Orders waiting for this warehouse to act: placed POs and approved SOs. */
    public function goodsReceiptQueueCount(): int
    {
        $statement = $this->pdo->query(
            "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Ordered', 'PartiallyReceived')"
        );

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    public function goodsIssueQueueCount(): int
    {
        $statement = $this->pdo->query("SELECT COUNT(*) FROM sales_orders WHERE status = 'Approved'");

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /** @param int|null $createdBy restricts to one seller (§1.2) */
    public function salesOrderValue(?int $createdBy = null): float
    {
        $sql = "SELECT COALESCE(SUM(i.quantity * i.selling_price), 0)
                  FROM sales_order_items i
                  JOIN sales_orders so ON so.id = i.sales_order_id
                 WHERE so.status <> 'Cancelled'";
        $parameters = [];

        if ($createdBy !== null) {
            $sql .= ' AND so.created_by = ?';
            $parameters[] = $createdBy;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return (float) $statement->fetchColumn();
    }

    /**
     * @return array<string,int>
     * @param list<array<string,mixed>> $rows
     */
    private function asStatusMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['status']] = (int) $row['total'];
        }

        return $map;
    }
}
