<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\AuthenticatedUser;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\PurchaseOrderStatus;
use App\Entity\Role;
use App\Repository\InMemoryPurchaseOrderRepository;
use App\Repository\InMemoryStockLedgerRepository;
use App\Repository\InMemoryStockRepository;
use App\Service\Exception\AuthorizationException;
use App\Service\PurchaseOrderService;
use App\Service\StockService;
use App\Support\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeTransactionManager;

/**
 * Logic area 5 — purchase order lifecycle and partial receipt (PO-01, D1).
 */
final class PurchaseOrderServiceTest extends TestCase
{
    private InMemoryPurchaseOrderRepository $orders;
    private InMemoryStockRepository $stock;
    private InMemoryStockLedgerRepository $ledger;
    private PurchaseOrderService $service;

    protected function setUp(): void
    {
        $this->orders = new InMemoryPurchaseOrderRepository();
        $this->stock = new InMemoryStockRepository(['1:1' => 0, '2:1' => 0]);
        $this->ledger = new InMemoryStockLedgerRepository();
        $transactions = new FakeTransactionManager();

        $this->service = new PurchaseOrderService(
            $this->orders,
            new StockService($this->stock, $this->ledger, $transactions),
            $transactions,
        );
    }

    private function admin(): AuthenticatedUser
    {
        return new AuthenticatedUser(1, 'Admin', Role::Admin);
    }

    private function warehouse(): AuthenticatedUser
    {
        return new AuthenticatedUser(4, 'Storeman', Role::WarehouseStaff);
    }

    private function sales(): AuthenticatedUser
    {
        return new AuthenticatedUser(2, 'Seller', Role::Sales);
    }

    /** @param list<array{product_id:int,quantity:int,purchase_price:float}> $lines */
    private function givenOrder(AuthenticatedUser $actor, array $lines, bool $placed = true): int
    {
        $id = $this->service->create($actor, 1, 1, date('Y-m-d'), $lines);
        if ($placed) {
            $this->service->place($this->admin(), $id);
        }

        return $id;
    }

    // --- D1: who may raise, who may place ---------------------------------

    public function testWarehouseStaffCanRaiseADraftPurchaseOrder(): void
    {
        $id = $this->service->create($this->warehouse(), 1, 1, date('Y-m-d'), [
            ['product_id' => 1, 'quantity' => 10, 'purchase_price' => 1000.0],
        ]);

        $order = $this->orders->findById($id);
        self::assertNotNull($order);
        self::assertSame(PurchaseOrderStatus::Draft, $order->status, 'A new order is always a draft.');
    }

    public function testWarehouseStaffCannotPlaceTheOrderWithTheSupplier(): void
    {
        $id = $this->service->create($this->warehouse(), 1, 1, date('Y-m-d'), [
            ['product_id' => 1, 'quantity' => 10, 'purchase_price' => 1000.0],
        ]);

        // Draft -> Ordered is the financial commitment, so it is Admin-only (D1).
        $this->expectException(AuthorizationException::class);
        $this->service->place($this->warehouse(), $id);
    }

    public function testSalesCannotTouchPurchaseOrdersAtAll(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->create($this->sales(), 1, 1, date('Y-m-d'), [
            ['product_id' => 1, 'quantity' => 1, 'purchase_price' => 1.0],
        ]);
    }

    // --- status transitions ------------------------------------------------

    public function testGoodsCannotBeReceivedAgainstADraft(): void
    {
        $id = $this->service->create($this->admin(), 1, 1, date('Y-m-d'), [
            ['product_id' => 1, 'quantity' => 10, 'purchase_price' => 1000.0],
        ]);
        $order = $this->orders->findById($id);
        self::assertNotNull($order);
        $itemId = (int) $order->items[0]->id;

        $this->expectException(ValidationException::class);
        $this->service->receiveGoods($this->warehouse(), $id, [$itemId => 5]);
    }

    public function testACancelledOrderCannotBePlaced(): void
    {
        $id = $this->service->create($this->admin(), 1, 1, date('Y-m-d'), [
            ['product_id' => 1, 'quantity' => 5, 'purchase_price' => 100.0],
        ]);
        $this->service->cancel($this->admin(), $id);

        $this->expectException(ValidationException::class);
        $this->service->place($this->admin(), $id);
    }

    public function testAFutureOrderDateIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->admin(), 1, 1, date('Y-m-d', strtotime('+10 days')), [
            ['product_id' => 1, 'quantity' => 5, 'purchase_price' => 100.0],
        ]);
    }

    public function testAnOrderWithNoLinesIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->admin(), 1, 1, date('Y-m-d'), []);
    }

    // --- goods receipt -----------------------------------------------------

    public function testFullReceiptIncreasesStockAndClosesTheOrder(): void
    {
        $id = $this->givenOrder($this->admin(), [['product_id' => 1, 'quantity' => 20, 'purchase_price' => 500.0]]);
        $order = $this->orders->findById($id);
        self::assertNotNull($order);

        $this->service->receiveGoods($this->warehouse(), $id, [(int) $order->items[0]->id => 20]);

        self::assertSame(20, $this->stock->balance(1, 1));
        self::assertSame(20, $this->ledger->balanceFromLedger(1, 1));

        $after = $this->orders->findById($id);
        self::assertNotNull($after);
        self::assertSame(PurchaseOrderStatus::Received, $after->status);
    }

    /**
     * PO-01 requires partial receipt, with the remainder still tracked.
     */
    public function testPartialReceiptLeavesTheRemainderOutstanding(): void
    {
        $id = $this->givenOrder($this->admin(), [['product_id' => 1, 'quantity' => 20, 'purchase_price' => 500.0]]);
        $order = $this->orders->findById($id);
        self::assertNotNull($order);
        $itemId = (int) $order->items[0]->id;

        $this->service->receiveGoods($this->warehouse(), $id, [$itemId => 8]);

        $after = $this->orders->findById($id);
        self::assertNotNull($after);
        self::assertSame(PurchaseOrderStatus::PartiallyReceived, $after->status);
        self::assertSame(8, $after->items[0]->receivedQuantity);
        self::assertSame(12, $after->items[0]->outstanding(), 'The remainder stays on the order.');
        self::assertSame(8, $this->stock->balance(1, 1), 'Only what arrived is in stock.');

        // The rest arrives later.
        $this->service->receiveGoods($this->warehouse(), $id, [$itemId => 12]);

        $final = $this->orders->findById($id);
        self::assertNotNull($final);
        self::assertSame(PurchaseOrderStatus::Received, $final->status);
        self::assertSame(0, $final->items[0]->outstanding());
        self::assertSame(20, $this->stock->balance(1, 1));
    }

    public function testReceivingMoreThanOrderedIsRejected(): void
    {
        $id = $this->givenOrder($this->admin(), [['product_id' => 1, 'quantity' => 10, 'purchase_price' => 500.0]]);
        $order = $this->orders->findById($id);
        self::assertNotNull($order);

        $this->expectException(ValidationException::class);
        $this->service->receiveGoods($this->warehouse(), $id, [(int) $order->items[0]->id => 11]);
    }

    public function testReceivingNothingIsRejected(): void
    {
        $id = $this->givenOrder($this->admin(), [['product_id' => 1, 'quantity' => 10, 'purchase_price' => 500.0]]);

        $this->expectException(ValidationException::class);
        $this->service->receiveGoods($this->warehouse(), $id, []);
    }

    /**
     * A multi-line order is only Received once EVERY line is complete — a
     * per-line flag would report the order closed while goods are still due.
     */
    public function testAMultiLineOrderStaysPartialUntilEveryLineIsComplete(): void
    {
        $id = $this->givenOrder($this->admin(), [
            ['product_id' => 1, 'quantity' => 10, 'purchase_price' => 100.0],
            ['product_id' => 2, 'quantity' => 5, 'purchase_price' => 200.0],
        ]);
        $order = $this->orders->findById($id);
        self::assertNotNull($order);

        // Line 1 arrives complete, line 2 not at all.
        $this->service->receiveGoods($this->warehouse(), $id, [(int) $order->items[0]->id => 10]);

        $after = $this->orders->findById($id);
        self::assertNotNull($after);
        self::assertSame(PurchaseOrderStatus::PartiallyReceived, $after->status);
        self::assertTrue($after->hasOutstandingItems());

        $this->service->receiveGoods($this->warehouse(), $id, [(int) $order->items[1]->id => 5]);

        $final = $this->orders->findById($id);
        self::assertNotNull($final);
        self::assertSame(PurchaseOrderStatus::Received, $final->status);
        self::assertSame(10, $this->stock->balance(1, 1));
        self::assertSame(5, $this->stock->balance(2, 1));
    }

    public function testEveryReceiptIsTraceableToItsPurchaseOrder(): void
    {
        $id = $this->givenOrder($this->admin(), [['product_id' => 1, 'quantity' => 4, 'purchase_price' => 100.0]]);
        $order = $this->orders->findById($id);
        self::assertNotNull($order);

        $this->service->receiveGoods($this->warehouse(), $id, [(int) $order->items[0]->id => 4]);

        $entry = $this->ledger->entries[0];
        self::assertSame($id, $entry->referenceId);
        self::assertSame(4, $entry->performedBy, 'The ledger records who received the goods.');
    }
}
