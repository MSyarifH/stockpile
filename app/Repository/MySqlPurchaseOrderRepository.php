<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\PurchaseOrderStatus;
use PDO;

final class MySqlPurchaseOrderRepository implements PurchaseOrderRepository
{
    private const SELECT = '
        SELECT po.id, po.po_number, po.supplier_id, po.warehouse_id, po.status,
               po.order_date, po.created_by,
               s.name AS supplier_name, w.name AS warehouse_name, u.name AS created_by_name
          FROM purchase_orders po
          JOIN suppliers s ON s.id = po.supplier_id
          JOIN warehouses w ON w.id = po.warehouse_id
          JOIN users u ON u.id = po.created_by';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(): array
    {
        $statement = $this->pdo->query(self::SELECT . ' ORDER BY po.order_date DESC, po.id DESC');
        $rows = $statement === false ? [] : $statement->fetchAll();

        return array_map(static fn (array $row): PurchaseOrder => PurchaseOrder::fromRow($row), $rows);
    }

    public function findById(int $id): ?PurchaseOrder
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE po.id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return PurchaseOrder::fromRow($row, $this->itemsFor($id));
    }

    /** @return list<PurchaseOrderItem> */
    private function itemsFor(int $orderId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.id, i.product_id, i.quantity, i.received_quantity, i.purchase_price,
                    p.name AS product_name, p.sku AS product_sku
               FROM purchase_order_items i
               JOIN products p ON p.id = i.product_id
              WHERE i.purchase_order_id = ?
              ORDER BY i.id'
        );
        $statement->execute([$orderId]);

        return array_map(
            static fn (array $row): PurchaseOrderItem => PurchaseOrderItem::fromRow($row),
            $statement->fetchAll(),
        );
    }

    /**
     * Numbers are sequential per year. Derived from the highest existing number
     * rather than a counter table; the UNIQUE index on po_number is what
     * actually guarantees no duplicate survives a race.
     */
    public function nextNumber(): string
    {
        $year = date('Y');
        $statement = $this->pdo->prepare(
            "SELECT po_number FROM purchase_orders WHERE po_number LIKE ? ORDER BY po_number DESC LIMIT 1"
        );
        $statement->execute(['PO-' . $year . '-%']);
        $last = $statement->fetchColumn();

        $sequence = is_string($last) ? ((int) substr($last, -4)) + 1 : 1;

        return sprintf('PO-%s-%04d', $year, $sequence);
    }

    public function create(PurchaseOrder $order): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO purchase_orders (po_number, supplier_id, warehouse_id, status, order_date, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $order->poNumber,
            $order->supplierId,
            $order->warehouseId,
            $order->status->value,
            $order->orderDate,
            $order->createdBy,
        ]);
        $orderId = (int) $this->pdo->lastInsertId();

        $itemStatement = $this->pdo->prepare(
            'INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, received_quantity, purchase_price)
             VALUES (?, ?, ?, 0, ?)'
        );
        foreach ($order->items as $item) {
            $itemStatement->execute([$orderId, $item->productId, $item->quantity, $item->purchasePrice]);
        }

        return $orderId;
    }

    public function updateStatus(int $id, PurchaseOrderStatus $status): void
    {
        $statement = $this->pdo->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?');
        $statement->execute([$status->value, $id]);
    }

    public function addReceivedQuantity(int $itemId, int $quantity): void
    {
        // The CHECK constraint received_quantity <= quantity is the backstop if
        // the service ever miscalculates.
        $statement = $this->pdo->prepare(
            'UPDATE purchase_order_items SET received_quantity = received_quantity + ? WHERE id = ?'
        );
        $statement->execute([$quantity, $itemId]);
    }
}
