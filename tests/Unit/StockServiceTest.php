<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\MovementCommand;
use App\Entity\MovementType;
use App\Entity\ReferenceType;
use App\Repository\InMemoryStockLedgerRepository;
use App\Repository\InMemoryStockRepository;
use App\Service\Exception\InsufficientStockException;
use App\Service\StockService;
use App\Support\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeTransactionManager;

/**
 * Logic area 4 — stock movement rules (ARCH-02, §1.3).
 *
 * These prove the RULES with no database. The concurrency MECHANISM is a
 * property of MySQL locking and cannot be shown here, so it is proven
 * separately by an integration test with two real connections (TEST-02).
 */
final class StockServiceTest extends TestCase
{
    private InMemoryStockRepository $stock;
    private InMemoryStockLedgerRepository $ledger;
    private FakeTransactionManager $transactions;
    private StockService $service;

    protected function setUp(): void
    {
        $this->stock = new InMemoryStockRepository(['1:1' => 10, '2:1' => 5]);
        $this->ledger = new InMemoryStockLedgerRepository();
        $this->transactions = new FakeTransactionManager();
        $this->service = new StockService($this->stock, $this->ledger, $this->transactions);
    }

    private function command(int $productId, int $warehouseId, int $quantity): MovementCommand
    {
        return new MovementCommand($productId, $warehouseId, $quantity, 4, ReferenceType::SalesOrder, 99);
    }

    public function testReceiptIncreasesStockAndWritesOneLedgerRow(): void
    {
        $this->service->receive($this->command(1, 1, 25));

        self::assertSame(35, $this->stock->balance(1, 1));
        self::assertCount(1, $this->ledger->entries);
        self::assertSame(MovementType::Receipt, $this->ledger->entries[0]->movementType);
        self::assertSame(25, $this->ledger->entries[0]->quantity, 'Receipt is stored as a positive delta.');
    }

    public function testIssueDecreasesStockAndWritesANegativeLedgerRow(): void
    {
        $this->service->issue($this->command(1, 1, 4));

        self::assertSame(6, $this->stock->balance(1, 1));
        self::assertSame(-4, $this->ledger->entries[0]->quantity, 'Issue is stored as a negative delta.');
    }

    /**
     * The core invariant: whatever happens, replaying the ledger must reproduce
     * the balance. This is the same check the assessor can run in SQL.
     */
    public function testLedgerAlwaysReconcilesToTheBalance(): void
    {
        $this->service->receive($this->command(1, 1, 30));
        $this->service->issue($this->command(1, 1, 12));
        $this->service->issue($this->command(1, 1, 3));

        // Opening balance of 10 was not created through the service, so compare
        // the movement of the balance against the movement recorded.
        self::assertSame(25, $this->stock->balance(1, 1));
        self::assertSame(15, $this->ledger->balanceFromLedger(1, 1), '30 - 12 - 3 = 15');
        self::assertSame(10 + 15, $this->stock->balance(1, 1));
    }

    public function testIssueIsRejectedWhenStockIsInsufficient(): void
    {
        $this->expectException(InsufficientStockException::class);
        $this->service->issue($this->command(1, 1, 11));   // only 10 available
    }

    public function testRejectedIssueLeavesStockAndLedgerUntouched(): void
    {
        try {
            $this->service->issue($this->command(1, 1, 11));
            self::fail('Expected the issue to be rejected.');
        } catch (InsufficientStockException $e) {
            self::assertSame(11, $e->requested);
            self::assertSame(10, $e->available);
        }

        self::assertSame(10, $this->stock->balance(1, 1), 'Balance must be unchanged.');
        self::assertSame([], $this->ledger->entries, 'No ledger row may be written for a rejected issue.');
        self::assertSame(1, $this->transactions->rolledBack);
        self::assertSame(0, $this->transactions->committed);
    }

    public function testIssuingExactlyTheAvailableQuantityIsAllowed(): void
    {
        $this->service->issue($this->command(1, 1, 10));

        self::assertSame(0, $this->stock->balance(1, 1), 'Stock may reach zero, just never go below.');
    }

    /**
     * A multi-line order must be all-or-nothing. If line 2 cannot be satisfied,
     * line 1 must not have been written either — otherwise stock leaves the
     * warehouse for an order that was never fulfilled.
     */
    public function testMultiLineIssueIsAllOrNothing(): void
    {
        $this->expectException(InsufficientStockException::class);

        try {
            $this->service->issueAll([
                $this->command(1, 1, 5),    // fine on its own
                $this->command(2, 1, 50),   // only 5 available - fails
            ]);
        } finally {
            self::assertSame(10, $this->stock->balance(1, 1), 'The first line must be rolled back too.');
            self::assertSame(5, $this->stock->balance(2, 1));
            self::assertSame([], $this->ledger->entries);
        }
    }

    /**
     * Two lines of the same order hitting the SAME stock row must be summed
     * before the check. Comparing them one at a time would let 6 + 6 pass
     * against a balance of 10.
     */
    public function testTwoLinesOnTheSameRowAreSummedBeforeTheStockCheck(): void
    {
        $this->expectException(InsufficientStockException::class);
        $this->service->issueAll([
            $this->command(1, 1, 6),
            $this->command(1, 1, 6),   // 12 total against a balance of 10
        ]);
    }

    public function testTheSameRowIsLockedOnlyOnce(): void
    {
        $this->service->receiveAll([
            $this->command(1, 1, 3),
            $this->command(1, 1, 4),
        ]);

        self::assertSame(['1:1'], $this->stock->lockOrder, 'One row, one lock.');
        self::assertSame(17, $this->stock->balance(1, 1), '10 + 3 + 4');
    }

    /**
     * Deadlock avoidance: rows are always locked in the same order regardless of
     * the order the lines arrive in. Two concurrent orders touching the same
     * products in opposite order would otherwise be able to deadlock.
     */
    public function testRowsAreLockedInADeterministicOrder(): void
    {
        $this->service->receiveAll([
            $this->command(2, 1, 1),
            $this->command(1, 1, 1),
        ]);
        $forwards = $this->stock->lockOrder;

        $this->setUp();
        $this->service->receiveAll([
            $this->command(1, 1, 1),
            $this->command(2, 1, 1),
        ]);

        self::assertSame($forwards, $this->stock->lockOrder);
        self::assertSame(['1:1', '2:1'], $forwards, 'Sorted by product, then warehouse.');
    }

    public function testStockIsTrackedSeparatelyPerWarehouse(): void
    {
        $this->service->receive($this->command(1, 2, 7));   // different warehouse

        self::assertSame(10, $this->stock->balance(1, 1), 'Warehouse 1 is unaffected.');
        self::assertSame(7, $this->stock->balance(1, 2));
    }

    public function testAZeroOrNegativeQuantityIsRejectedAtTheBoundary(): void
    {
        $this->expectException(ValidationException::class);
        // A negative "issue" would otherwise increase stock.
        new MovementCommand(1, 1, -5, 4);
    }

    public function testEveryMovementRecordsWhoDidItAndWhy(): void
    {
        $this->service->issue($this->command(1, 1, 2));

        $entry = $this->ledger->entries[0];
        self::assertSame(4, $entry->performedBy);
        self::assertSame(ReferenceType::SalesOrder, $entry->referenceType);
        self::assertSame(99, $entry->referenceId, 'Every movement traces back to its order.');
    }
}
