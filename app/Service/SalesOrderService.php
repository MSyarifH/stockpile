<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuthenticatedUser;
use App\Entity\MovementCommand;
use App\Entity\ReferenceType;
use App\Entity\Role;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Entity\SalesOrderStatus;
use App\Repository\SalesOrderRepository;
use App\Service\Exception\AuthorizationException;
use App\Support\Exception\HttpException;
use App\Support\Exception\ValidationException;
use App\Support\TransactionManager;

/**
 * SO-01. Sales orders, approval and goods issue.
 *
 * Every authorization rule lives HERE rather than in the controller, so it holds
 * identically for the HTML form, the JSON API and any CLI script. §8.2 treats
 * authorization enforced only in the frontend as a critical failure, and a check
 * that exists only in a controller is halfway to the same mistake.
 *
 * Stock is never written here — goods issue delegates to StockService.
 */
final class SalesOrderService
{
    public function __construct(
        private readonly SalesOrderRepository $orders,
        private readonly StockService $stock,
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * §1.2: Sales sees only its own orders. The filter is pushed into the query
     * so another user's order is never loaded, rather than loaded and hidden.
     *
     * @return list<SalesOrder>
     */
    public function list(AuthenticatedUser $actor): array
    {
        return $this->orders->all($actor->role === Role::Sales ? $actor->id : null);
    }

    public function find(AuthenticatedUser $actor, int $id): SalesOrder
    {
        $order = $this->orders->findById($id);
        if ($order === null) {
            throw HttpException::notFound('That sales order does not exist.');
        }

        // A Sales user reaching another user's order by URL gets 404, not 403:
        // confirming the order exists would leak that it does.
        if ($actor->role === Role::Sales && !$order->isOwnedBy($actor)) {
            throw HttpException::notFound('That sales order does not exist.');
        }

        return $order;
    }

    /**
     * @param list<array{product_id:int,quantity:int,selling_price:float}> $lines
     */
    public function create(
        AuthenticatedUser $actor,
        int $customerId,
        int $warehouseId,
        string $orderDate,
        array $lines,
    ): int {
        $this->assertCanSell($actor);
        $this->assertOrderDateIsSane($orderDate);

        if ($lines === []) {
            throw new ValidationException(['items' => 'A sales order needs at least one line.']);
        }

        $items = [];
        foreach ($lines as $index => $line) {
            if ($line['quantity'] <= 0) {
                throw new ValidationException(['items' => sprintf('Line %d: quantity must be at least 1.', $index + 1)]);
            }
            if ($line['selling_price'] < 0) {
                throw new ValidationException(['items' => sprintf('Line %d: price cannot be negative.', $index + 1)]);
            }
            $items[] = new SalesOrderItem(null, $line['product_id'], $line['quantity'], $line['selling_price']);
        }

        return $this->transactions->transactional(fn (): int => $this->orders->create(new SalesOrder(
            null,
            $this->orders->nextNumber(),
            $customerId,
            $warehouseId,
            SalesOrderStatus::Draft,
            $orderDate,
            $actor->id,
            null,
            null,
            $items,
        )));
    }

    /** Draft -> PendingApproval. Done by the owner, or by an Admin on their behalf. */
    public function submit(AuthenticatedUser $actor, int $id): void
    {
        $order = $this->find($actor, $id);

        if (!$order->isOwnedBy($actor) && $actor->role !== Role::Admin) {
            throw new AuthorizationException('You can only submit your own sales orders.');
        }

        $this->assertTransition($order->status, SalesOrderStatus::PendingApproval);
        $this->orders->updateStatus($id, SalesOrderStatus::PendingApproval);
    }

    /**
     * PendingApproval -> Approved.
     *
     * Two independent conditions, both required (§1.2, decision D2):
     *   1. the actor holds approval authority (Admin);
     *   2. the actor is not the person who raised the order.
     *
     * Condition 2 applies to Admins too. Reading §1.2's permission table alone
     * would give only condition 1, leaving a single person able to raise and
     * approve the same transaction — precisely what the control exists to stop.
     * A second Admin account is seeded so this rule cannot deadlock the system.
     */
    public function approve(AuthenticatedUser $actor, int $id): void
    {
        $order = $this->find($actor, $id);

        if ($actor->role !== Role::Admin) {
            throw new AuthorizationException('Only an Admin may approve a sales order.');
        }

        if ($order->isOwnedBy($actor)) {
            throw new AuthorizationException(
                'You cannot approve a sales order you raised yourself. '
                . 'Another Admin must review it.'
            );
        }

        $this->assertTransition($order->status, SalesOrderStatus::Approved);
        $this->orders->markApproved($id, $actor->id);
    }

    /** PendingApproval -> Draft, so the Sales user can correct and resubmit. */
    public function reject(AuthenticatedUser $actor, int $id): void
    {
        $order = $this->find($actor, $id);

        if ($actor->role !== Role::Admin) {
            throw new AuthorizationException('Only an Admin may reject a sales order.');
        }

        // Same separation as approval: reviewing your own work is not review.
        if ($order->isOwnedBy($actor)) {
            throw new AuthorizationException('You cannot review a sales order you raised yourself.');
        }

        $this->assertTransition($order->status, SalesOrderStatus::Draft);
        $this->orders->updateStatus($id, SalesOrderStatus::Draft);
    }

    public function cancel(AuthenticatedUser $actor, int $id): void
    {
        $order = $this->find($actor, $id);

        $isOwnDraft = $order->status === SalesOrderStatus::Draft && $order->isOwnedBy($actor);
        if ($actor->role !== Role::Admin && !$isOwnDraft) {
            throw new AuthorizationException('Only an Admin may cancel a submitted sales order.');
        }

        $this->assertTransition($order->status, SalesOrderStatus::Cancelled);
        $this->orders->updateStatus($id, SalesOrderStatus::Cancelled);
    }

    /**
     * Goods issue. Only from Approved, and only by Admin or Warehouse Staff.
     *
     * Stock is checked and decremented by StockService inside a locked
     * transaction. There is no reservation at approval time (decision D3), so an
     * approved order can still fail here if the stock has since gone — which is
     * exactly the scenario SO-01 requires to be demonstrable.
     *
     * The order becomes Fulfilled in the SAME transaction as the stock movement:
     * stock leaving the warehouse without the order closing, or the reverse,
     * would both break the audit trail.
     */
    public function issueGoods(AuthenticatedUser $actor, int $id): void
    {
        if (!$actor->is(Role::Admin, Role::WarehouseStaff)) {
            throw new AuthorizationException('Only Admin and Warehouse Staff can issue goods.');
        }

        $order = $this->find($actor, $id);

        if (!$order->status->acceptsGoodsIssue()) {
            throw new ValidationException([
                'status' => sprintf(
                    'Goods can only be issued for an approved order. This order is %s.',
                    $order->status->label(),
                ),
            ]);
        }

        $movements = [];
        foreach ($order->items as $item) {
            $movements[] = new MovementCommand(
                $item->productId,
                $order->warehouseId,
                $item->quantity,
                $actor->id,
                ReferenceType::SalesOrder,
                $id,
            );
        }

        $this->transactions->transactional(function () use ($id, $movements): void {
            // Throws InsufficientStockException, which rolls the whole thing
            // back — including the status change below.
            $this->stock->issueAll($movements);
            $this->orders->updateStatus($id, SalesOrderStatus::Fulfilled);
        });
    }

    private function assertTransition(SalesOrderStatus $from, SalesOrderStatus $to): void
    {
        if (!$from->canTransitionTo($to)) {
            throw new ValidationException([
                'status' => sprintf('A %s sales order cannot become %s.', $from->label(), $to->label()),
            ]);
        }
    }

    private function assertOrderDateIsSane(string $orderDate): void
    {
        $parsed = date_create_immutable($orderDate);
        if ($parsed === false) {
            throw new ValidationException(['order_date' => 'Enter a valid order date.']);
        }

        // The form already sets max=today, but the frontend is only a
        // convenience: a request that bypasses it must be refused here too,
        // because the backend is the source of truth (VAL-01).
        if ($parsed > new \DateTimeImmutable('today +1 day')) {
            throw new ValidationException(['order_date' => 'The order date cannot be in the future.']);
        }
    }

    /** Warehouse Staff do not raise sales orders (§1.2). */
    private function assertCanSell(AuthenticatedUser $actor): void
    {
        if (!$actor->is(Role::Admin, Role::Sales)) {
            throw new AuthorizationException('Only Admin and Sales can raise a sales order.');
        }
    }
}
