<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuthenticatedUser;
use App\Entity\MovementCommand;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\PurchaseOrderStatus;
use App\Entity\ReferenceType;
use App\Entity\Role;
use App\Repository\OrderFilter;
use App\Repository\PurchaseOrderRepository;
use App\Service\Exception\AuthorizationException;
use App\Support\Exception\HttpException;
use App\Support\Exception\ValidationException;
use App\Support\Page;
use App\Support\TransactionManager;

/**
 * PO-01. Purchase orders and goods receipt.
 *
 * This service never writes stock itself: goods receipt delegates to
 * StockService, which is the only writer of product_stocks and stock_ledger.
 *
 * Decision D1 is enforced here: Warehouse Staff may RAISE a purchase order, but
 * only an Admin may place it with the supplier. Draft -> Ordered is the point of
 * financial commitment, which mirrors the requisition/purchase-order split used
 * in real procurement.
 */
final class PurchaseOrderService
{
    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly StockService $stock,
        private readonly TransactionManager $transactions,
    ) {
    }

    /** @return list<PurchaseOrder> */
    public function list(AuthenticatedUser $actor): array
    {
        $this->assertCanHandlePurchasing($actor);

        return $this->orders->all();
    }

    /** @return Page<PurchaseOrder> */
    public function search(AuthenticatedUser $actor, OrderFilter $filter, int $page): Page
    {
        $this->assertCanHandlePurchasing($actor);

        return $this->orders->paginate($filter, $page);
    }

    public function find(AuthenticatedUser $actor, int $id): PurchaseOrder
    {
        $this->assertCanHandlePurchasing($actor);

        $order = $this->orders->findById($id);
        if ($order === null) {
            throw HttpException::notFound('That purchase order does not exist.');
        }

        return $order;
    }

    /**
     * Creates a Draft. Never Ordered — placing the order is a separate,
     * Admin-only step (D1).
     *
     * @param list<array{product_id:int,quantity:int,purchase_price:float}> $lines
     */
    public function create(
        AuthenticatedUser $actor,
        int $supplierId,
        int $warehouseId,
        string $orderDate,
        array $lines,
    ): int {
        $this->assertCanHandlePurchasing($actor);
        $this->assertOrderDateIsSane($orderDate);

        if ($lines === []) {
            throw new ValidationException(['items' => 'A purchase order needs at least one line.']);
        }

        $items = [];
        foreach ($lines as $index => $line) {
            if ($line['quantity'] <= 0) {
                throw new ValidationException(['items' => sprintf('Line %d: quantity must be at least 1.', $index + 1)]);
            }
            if ($line['purchase_price'] < 0) {
                throw new ValidationException(['items' => sprintf('Line %d: price cannot be negative.', $index + 1)]);
            }
            $items[] = new PurchaseOrderItem(null, $line['product_id'], $line['quantity'], 0, $line['purchase_price']);
        }

        return $this->transactions->transactional(fn (): int => $this->orders->create(new PurchaseOrder(
            null,
            $this->orders->nextNumber(),
            $supplierId,
            $warehouseId,
            PurchaseOrderStatus::Draft,
            $orderDate,
            $actor->id,
            $items,
        )));
    }

    /**
     * Draft -> Ordered. Admin only (D1): this is the point at which the company
     * commits money to a supplier.
     */
    public function place(AuthenticatedUser $actor, int $id): void
    {
        if ($actor->role !== Role::Admin) {
            throw new AuthorizationException(
                'Only an Admin may place a purchase order with the supplier. '
                . 'Warehouse Staff can raise it as a draft.'
            );
        }

        $order = $this->find($actor, $id);
        $this->assertTransition($order->status, PurchaseOrderStatus::Ordered);

        $this->orders->updateStatus($id, PurchaseOrderStatus::Ordered);
    }

    public function cancel(AuthenticatedUser $actor, int $id): void
    {
        $order = $this->find($actor, $id);

        // Warehouse Staff may withdraw their own draft; anything already placed
        // with a supplier is the Admin's to cancel.
        $isOwnDraft = $order->status === PurchaseOrderStatus::Draft && $order->createdBy === $actor->id;
        if ($actor->role !== Role::Admin && !$isOwnDraft) {
            throw new AuthorizationException('Only an Admin may cancel a purchase order that has been placed.');
        }

        $this->assertTransition($order->status, PurchaseOrderStatus::Cancelled);
        $this->orders->updateStatus($id, PurchaseOrderStatus::Cancelled);
    }

    /**
     * Goods receipt, full or partial (PO-01).
     *
     * Everything happens in one transaction: the received quantities, the stock
     * increase, the ledger rows and the new status either all land or none do.
     * StockService opens a transaction of its own; the transaction manager is
     * re-entrant so that inner call joins this one rather than committing early.
     *
     * @param array<int,int> $quantitiesByItemId how much of each line arrived now
     */
    public function receiveGoods(AuthenticatedUser $actor, int $id, array $quantitiesByItemId): void
    {
        $this->assertCanHandlePurchasing($actor);
        $order = $this->find($actor, $id);

        if (!$order->status->acceptsGoodsReceipt()) {
            throw new ValidationException([
                'status' => sprintf(
                    'Goods can only be received against an order that has been placed. This order is %s.',
                    $order->status->label(),
                ),
            ]);
        }

        $movements = [];
        $receipts = [];

        foreach ($order->items as $item) {
            $quantity = $quantitiesByItemId[$item->id] ?? 0;
            if ($quantity === 0) {
                continue;
            }

            if ($quantity < 0) {
                throw new ValidationException(['items' => 'Received quantity cannot be negative.']);
            }

            // Receiving more than was ordered is a data error, not a bonus.
            if ($quantity > $item->outstanding()) {
                throw new ValidationException([
                    'items' => sprintf(
                        '%s: cannot receive %d, only %d outstanding.',
                        $item->productSku ?? ('product ' . $item->productId),
                        $quantity,
                        $item->outstanding(),
                    ),
                ]);
            }

            $receipts[(int) $item->id] = $quantity;
            $movements[] = new MovementCommand(
                $item->productId,
                $order->warehouseId,
                $quantity,
                $actor->id,
                ReferenceType::PurchaseOrder,
                $id,
            );
        }

        if ($movements === []) {
            throw new ValidationException(['items' => 'Enter at least one quantity to receive.']);
        }

        $this->transactions->transactional(function () use ($id, $receipts, $movements): void {
            foreach ($receipts as $itemId => $quantity) {
                $this->orders->addReceivedQuantity($itemId, $quantity);
            }

            $this->stock->receiveAll($movements);

            // Re-read so the status is derived from what is now actually
            // recorded, rather than from what we believe we just wrote.
            $updated = $this->orders->findById($id);
            if ($updated !== null) {
                $this->orders->updateStatus($id, $updated->statusFromReceipts());
            }
        });
    }

    private function assertTransition(PurchaseOrderStatus $from, PurchaseOrderStatus $to): void
    {
        if (!$from->canTransitionTo($to)) {
            throw new ValidationException([
                'status' => sprintf('A %s purchase order cannot become %s.', $from->label(), $to->label()),
            ]);
        }
    }

    private function assertOrderDateIsSane(string $orderDate): void
    {
        $parsed = date_create_immutable($orderDate);
        if ($parsed === false) {
            throw new ValidationException(['order_date' => 'Enter a valid order date.']);
        }

        // A purchase order dated in the future has not happened yet; allowing it
        // would let stock be received against an order that does not exist.
        if ($parsed > new \DateTimeImmutable('today 23:59:59')) {
            throw new ValidationException(['order_date' => 'The order date cannot be in the future.']);
        }
    }

    /** Sales has no part in purchasing (§1.2). */
    private function assertCanHandlePurchasing(AuthenticatedUser $actor): void
    {
        if (!$actor->is(Role::Admin, Role::WarehouseStaff)) {
            throw new AuthorizationException('Only Admin and Warehouse Staff can work with purchase orders.');
        }
    }
}
