<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\AuthenticatedUser;
use App\Entity\Role;
use App\Entity\SalesOrderStatus;
use App\Repository\InMemorySalesOrderRepository;
use App\Repository\InMemoryStockLedgerRepository;
use App\Repository\InMemoryStockRepository;
use App\Service\Exception\AuthorizationException;
use App\Service\Exception\InsufficientStockException;
use App\Service\SalesOrderService;
use App\Service\StockService;
use App\Support\Exception\HttpException;
use App\Support\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeTransactionManager;

/**
 * Logic area 6 — sales order lifecycle, segregation of duties and goods issue
 * (SO-01, §1.2, decisions D2 and D3).
 *
 * These are the tests that prove the approval rule is enforced in the SERVICE.
 * A check that lived only in a controller would pass a browser demo and fail a
 * direct request.
 */
final class SalesOrderServiceTest extends TestCase
{
    private InMemorySalesOrderRepository $orders;
    private InMemoryStockRepository $stock;
    private InMemoryStockLedgerRepository $ledger;
    private SalesOrderService $service;

    protected function setUp(): void
    {
        $this->orders = new InMemorySalesOrderRepository();
        $this->stock = new InMemoryStockRepository(['1:1' => 20, '2:1' => 3]);
        $this->ledger = new InMemoryStockLedgerRepository();
        $transactions = new FakeTransactionManager();

        $this->service = new SalesOrderService(
            $this->orders,
            new StockService($this->stock, $this->ledger, $transactions),
            $transactions,
        );
    }

    private function adminOne(): AuthenticatedUser
    {
        return new AuthenticatedUser(1, 'First Admin', Role::Admin);
    }

    private function adminTwo(): AuthenticatedUser
    {
        return new AuthenticatedUser(7, 'Second Admin', Role::Admin);
    }

    private function sales(): AuthenticatedUser
    {
        return new AuthenticatedUser(2, 'Seller', Role::Sales);
    }

    private function otherSales(): AuthenticatedUser
    {
        return new AuthenticatedUser(3, 'Other Seller', Role::Sales);
    }

    private function warehouse(): AuthenticatedUser
    {
        return new AuthenticatedUser(4, 'Storeman', Role::WarehouseStaff);
    }

    /** @param list<array{product_id:int,quantity:int,selling_price:float}>|null $lines */
    private function givenSubmittedOrder(?AuthenticatedUser $owner = null, ?array $lines = null): int
    {
        $owner ??= $this->sales();
        $lines ??= [['product_id' => 1, 'quantity' => 5, 'selling_price' => 1500.0]];

        $id = $this->service->create($owner, 1, 1, date('Y-m-d'), $lines);
        $this->service->submit($owner, $id);

        return $id;
    }

    private function statusOf(int $id): SalesOrderStatus
    {
        $order = $this->orders->findById($id);
        self::assertNotNull($order);

        return $order->status;
    }

    // --- segregation of duties (D2) ---------------------------------------

    public function testSalesCannotApproveAnyOrder(): void
    {
        $id = $this->givenSubmittedOrder();

        $this->expectException(AuthorizationException::class);
        $this->service->approve($this->sales(), $id);
    }

    public function testSalesCannotApproveEvenTheirOwnOrder(): void
    {
        $owner = $this->sales();
        $id = $this->givenSubmittedOrder($owner);

        // §1.2 is explicit: "Tidak, meski order miliknya sendiri".
        $this->expectException(AuthorizationException::class);
        $this->service->approve($owner, $id);
    }

    public function testWarehouseStaffCannotApprove(): void
    {
        $id = $this->givenSubmittedOrder();

        $this->expectException(AuthorizationException::class);
        $this->service->approve($this->warehouse(), $id);
    }

    /**
     * Decision D2, the strict reading: the creator may never approve, Admin
     * included. Otherwise one person could raise and approve the same
     * transaction — on the most privileged role.
     */
    public function testAnAdminCannotApproveAnOrderTheyRaisedThemselves(): void
    {
        $admin = $this->adminOne();
        $id = $this->givenSubmittedOrder($admin);

        $this->expectException(AuthorizationException::class);
        $this->service->approve($admin, $id);
    }

    public function testADifferentAdminCanApproveThatSameOrder(): void
    {
        $id = $this->givenSubmittedOrder($this->adminOne());

        // This is why a second Admin account exists in the seed: the rule above
        // would otherwise make such an order permanently unapprovable.
        $this->service->approve($this->adminTwo(), $id);

        $order = $this->orders->findById($id);
        self::assertNotNull($order);
        self::assertSame(SalesOrderStatus::Approved, $order->status);
        self::assertSame(7, $order->approvedBy, 'The approver is recorded for audit.');
        self::assertNotSame($order->createdBy, $order->approvedBy);
    }

    public function testApprovingRecordsWhoApprovedIt(): void
    {
        $id = $this->givenSubmittedOrder();
        $this->service->approve($this->adminOne(), $id);

        $order = $this->orders->findById($id);
        self::assertNotNull($order);
        self::assertSame(1, $order->approvedBy);
        self::assertNotNull($order->approvedAt);
    }

    public function testRejectionIsSubjectToTheSameSeparation(): void
    {
        $admin = $this->adminOne();
        $id = $this->givenSubmittedOrder($admin);

        $this->expectException(AuthorizationException::class);
        $this->service->reject($admin, $id);
    }

    // --- ownership ---------------------------------------------------------

    public function testSalesSeesOnlyTheirOwnOrders(): void
    {
        $this->givenSubmittedOrder($this->sales());
        $this->givenSubmittedOrder($this->otherSales());

        self::assertCount(1, $this->service->list($this->sales()));
        self::assertCount(2, $this->service->list($this->adminOne()), 'An Admin sees everything.');
    }

    public function testSalesCannotOpenAnotherSellersOrderByUrl(): void
    {
        $id = $this->givenSubmittedOrder($this->otherSales());

        // 404 rather than 403: confirming it exists would itself leak information.
        $this->expectException(HttpException::class);
        $this->service->find($this->sales(), $id);
    }

    public function testAFutureOrderDateIsRejectedByTheServiceNotJustTheForm(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->sales(), 1, 1, date('Y-m-d', strtotime('+7 days')), [
            ['product_id' => 1, 'quantity' => 1, 'selling_price' => 10.0],
        ]);
    }

    public function testWarehouseStaffCannotRaiseASalesOrder(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->create($this->warehouse(), 1, 1, date('Y-m-d'), [
            ['product_id' => 1, 'quantity' => 1, 'selling_price' => 10.0],
        ]);
    }

    // --- status transitions ------------------------------------------------

    public function testGoodsCannotBeIssuedBeforeApproval(): void
    {
        $id = $this->givenSubmittedOrder();   // PendingApproval

        $this->expectException(ValidationException::class);
        $this->service->issueGoods($this->warehouse(), $id);
    }

    public function testGoodsCannotBeIssuedTwice(): void
    {
        $id = $this->givenSubmittedOrder();
        $this->service->approve($this->adminOne(), $id);
        $this->service->issueGoods($this->warehouse(), $id);

        $this->expectException(ValidationException::class);
        $this->service->issueGoods($this->warehouse(), $id);
    }

    public function testAFulfilledOrderCannotBeCancelled(): void
    {
        $id = $this->givenSubmittedOrder();
        $this->service->approve($this->adminOne(), $id);
        $this->service->issueGoods($this->warehouse(), $id);

        $this->expectException(ValidationException::class);
        $this->service->cancel($this->adminOne(), $id);
    }

    public function testTheFullHappyPathDraftToFulfilled(): void
    {
        $id = $this->service->create($this->sales(), 1, 1, date('Y-m-d'), [
            ['product_id' => 1, 'quantity' => 5, 'selling_price' => 1500.0],
        ]);
        self::assertSame(SalesOrderStatus::Draft, $this->statusOf($id));

        $this->service->submit($this->sales(), $id);
        self::assertSame(SalesOrderStatus::PendingApproval, $this->statusOf($id));

        $this->service->approve($this->adminOne(), $id);
        self::assertSame(SalesOrderStatus::Approved, $this->statusOf($id));

        $this->service->issueGoods($this->warehouse(), $id);
        self::assertSame(SalesOrderStatus::Fulfilled, $this->statusOf($id));

        self::assertSame(15, $this->stock->balance(1, 1), '20 - 5');
        self::assertSame(-5, $this->ledger->balanceFromLedger(1, 1));
    }

    // --- goods issue and stock (D3) ----------------------------------------

    /**
     * Decision D3: stock is NOT reserved at approval, so an approved order can
     * still fail at goods issue. SO-01 requires exactly this to be demonstrable.
     */
    public function testAnApprovedOrderStillFailsIfStockHasGone(): void
    {
        $id = $this->givenSubmittedOrder(null, [['product_id' => 2, 'quantity' => 3, 'selling_price' => 100.0]]);
        $this->service->approve($this->adminOne(), $id);

        // Something else consumed the stock between approval and issue.
        $this->stock->adjust(2, 1, -2);   // 3 -> 1

        $this->expectException(InsufficientStockException::class);
        $this->service->issueGoods($this->warehouse(), $id);
    }

    public function testAFailedGoodsIssueLeavesTheOrderApprovedAndStockUntouched(): void
    {
        $id = $this->givenSubmittedOrder(null, [['product_id' => 2, 'quantity' => 10, 'selling_price' => 100.0]]);
        $this->service->approve($this->adminOne(), $id);

        try {
            $this->service->issueGoods($this->warehouse(), $id);
            self::fail('Expected the issue to be rejected.');
        } catch (InsufficientStockException) {
            // expected
        }

        $order = $this->orders->findById($id);
        self::assertNotNull($order);
        self::assertSame(SalesOrderStatus::Approved, $order->status, 'The order must not be marked Fulfilled.');
        self::assertSame(3, $this->stock->balance(2, 1), 'Stock unchanged.');
        self::assertSame([], $this->ledger->entries, 'No ledger row written.');
    }

    public function testGoodsIssueIsTraceableToTheOrderAndTheStaffMember(): void
    {
        $id = $this->givenSubmittedOrder();
        $this->service->approve($this->adminOne(), $id);
        $this->service->issueGoods($this->warehouse(), $id);

        $entry = $this->ledger->entries[0];
        self::assertSame($id, $entry->referenceId);
        self::assertSame(4, $entry->performedBy);
    }
}
