<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Entity\SalesOrderStatus;
use App\Support\Page;
use PDO;

final class MySqlSalesOrderRepository implements SalesOrderRepository
{
    private const SELECT = '
        SELECT so.id, so.so_number, so.customer_id, so.warehouse_id, so.status,
               so.order_date, so.created_by, so.approved_by, so.approved_at,
               c.name AS customer_name, w.name AS warehouse_name,
               cu.name AS created_by_name, au.name AS approved_by_name,
               -- Correlated subquery rather than a JOIN + GROUP BY: the outer
               -- query already joins four tables, and grouping them all to
               -- aggregate one column would force a temporary table.
               (SELECT COALESCE(SUM(i.quantity * i.selling_price), 0)
                  FROM sales_order_items i
                 WHERE i.sales_order_id = so.id) AS order_total
          FROM sales_orders so
          JOIN customers c ON c.id = so.customer_id
          JOIN warehouses w ON w.id = so.warehouse_id
          JOIN users cu ON cu.id = so.created_by
          LEFT JOIN users au ON au.id = so.approved_by';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(?int $createdBy = null): array
    {
        $sql = self::SELECT;
        $parameters = [];

        if ($createdBy !== null) {
            $sql .= ' WHERE so.created_by = ?';
            $parameters[] = $createdBy;
        }
        $sql .= ' ORDER BY so.order_date DESC, so.id DESC';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return array_map(
            static fn (array $row): SalesOrder => SalesOrder::fromRow($row),
            $statement->fetchAll(),
        );
    }

    public function between(string $from, string $to, ?int $createdBy = null): array
    {
        $conditions = ['so.order_date >= ?', 'so.order_date <= ?'];
        $parameters = [$from, $to];

        if ($createdBy !== null) {
            $conditions[] = 'so.created_by = ?';
            $parameters[] = $createdBy;
        }

        $sql = self::SELECT . ' WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY so.order_date DESC, so.id DESC';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return array_map(
            static fn (array $row): SalesOrder => SalesOrder::fromRow($row),
            $statement->fetchAll(),
        );
    }

    public function paginate(OrderFilter $filter, int $page, ?int $createdBy = null): Page
    {
        $conditions = [];
        $parameters = [];

        // The ownership restriction is part of the QUERY, so another seller's
        // order is never loaded — not loaded and then filtered out in PHP.
        if ($createdBy !== null) {
            $conditions[] = 'so.created_by = ?';
            $parameters[] = $createdBy;
        }

        if ($filter->search !== '') {
            $conditions[] = '(so.so_number LIKE ? OR c.name LIKE ?)';
            $parameters[] = '%' . $filter->search . '%';
            $parameters[] = '%' . $filter->search . '%';
        }

        if ($filter->status !== null) {
            $conditions[] = 'so.status = ?';
            $parameters[] = $filter->status;
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $countStatement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM sales_orders so JOIN customers c ON c.id = so.customer_id' . $where
        );
        $countStatement->execute($parameters);
        $total = (int) $countStatement->fetchColumn();

        $sql = self::SELECT . $where
            . ' ORDER BY so.order_date ' . $filter->sqlDirection() . ', so.id ' . $filter->sqlDirection()
            . ' LIMIT ? OFFSET ?';
        $statement = $this->pdo->prepare($sql);

        $position = 1;
        foreach ($parameters as $value) {
            $statement->bindValue($position++, $value);
        }
        $statement->bindValue($position++, Page::PER_PAGE, PDO::PARAM_INT);
        $statement->bindValue($position, Page::offsetFor($page), PDO::PARAM_INT);
        $statement->execute();

        $items = array_map(
            static fn (array $row): SalesOrder => SalesOrder::fromRow($row),
            $statement->fetchAll(),
        );

        return new Page($items, $total, Page::normalisePage($page));
    }

    public function findById(int $id): ?SalesOrder
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE so.id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return SalesOrder::fromRow($row, $this->itemsFor($id, (int) $row['warehouse_id']));
    }

    /**
     * Line items with the current balance in the order's source warehouse.
     *
     * That balance is for DISPLAY only (decision D3). It is read without a lock
     * and may be stale by the time goods are issued — the authoritative check
     * happens inside StockService's locked transaction.
     *
     * @return list<SalesOrderItem>
     */
    private function itemsFor(int $orderId, int $warehouseId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.id, i.product_id, i.quantity, i.selling_price,
                    p.name AS product_name, p.sku AS product_sku,
                    COALESCE(ps.quantity, 0) AS available
               FROM sales_order_items i
               JOIN products p ON p.id = i.product_id
               LEFT JOIN product_stocks ps ON ps.product_id = i.product_id AND ps.warehouse_id = ?
              WHERE i.sales_order_id = ?
              ORDER BY i.id'
        );
        $statement->execute([$warehouseId, $orderId]);

        return array_map(
            static fn (array $row): SalesOrderItem => SalesOrderItem::fromRow($row),
            $statement->fetchAll(),
        );
    }

    public function nextNumber(): string
    {
        $year = date('Y');
        $statement = $this->pdo->prepare(
            'SELECT so_number FROM sales_orders WHERE so_number LIKE ? ORDER BY so_number DESC LIMIT 1'
        );
        $statement->execute(['SO-' . $year . '-%']);
        $last = $statement->fetchColumn();

        $sequence = is_string($last) ? ((int) substr($last, -4)) + 1 : 1;

        return sprintf('SO-%s-%04d', $year, $sequence);
    }

    public function create(SalesOrder $order): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO sales_orders (so_number, customer_id, warehouse_id, status, order_date, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $order->soNumber,
            $order->customerId,
            $order->warehouseId,
            $order->status->value,
            $order->orderDate,
            $order->createdBy,
        ]);
        $orderId = (int) $this->pdo->lastInsertId();

        $itemStatement = $this->pdo->prepare(
            'INSERT INTO sales_order_items (sales_order_id, product_id, quantity, selling_price)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($order->items as $item) {
            $itemStatement->execute([$orderId, $item->productId, $item->quantity, $item->sellingPrice]);
        }

        return $orderId;
    }

    public function updateStatus(int $id, SalesOrderStatus $status): void
    {
        $statement = $this->pdo->prepare('UPDATE sales_orders SET status = ? WHERE id = ?');
        $statement->execute([$status->value, $id]);
    }

    public function markApproved(int $id, int $approvedBy): void
    {
        // Status and approver are written together: an Approved order with no
        // recorded approver would destroy the audit trail §1.2 depends on.
        $statement = $this->pdo->prepare(
            'UPDATE sales_orders SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?'
        );
        $statement->execute([SalesOrderStatus::Approved->value, $approvedBy, $id]);
    }
}
