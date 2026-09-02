<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\LedgerEntry;
use App\Entity\MovementCommand;
use App\Entity\MovementType;
use App\Entity\ReferenceType;
use App\Repository\MySqlStockLedgerRepository;
use App\Repository\MySqlStockRepository;
use App\Service\Exception\InsufficientStockException;
use App\Service\StockService;
use App\Support\PdoTransactionManager;
use PDO;
use PDOException;
use RuntimeException;

/**
 * TEST-02 — against real MySQL in Docker.
 */
final class StockMovementTest extends IntegrationTestCase
{
    private function serviceOn(PDO $pdo): StockService
    {
        return new StockService(
            new MySqlStockRepository($pdo),
            new MySqlStockLedgerRepository($pdo),
            new PdoTransactionManager($pdo),
        );
    }

    public function testGoodsReceiptIncreasesStockAndWritesTheLedgerEndToEnd(): void
    {
        [$productId, $warehouseId] = $this->givenProductWithStock(0);

        $this->serviceOn($this->pdo)->receive(new MovementCommand(
            $productId,
            $warehouseId,
            40,
            $this->anyUserId(),
            ReferenceType::PurchaseOrder,
            null,
        ));

        self::assertSame(40, $this->balance($productId, $warehouseId));
        self::assertSame(
            40,
            $this->ledgerSum($productId, $warehouseId),
            'The ledger must reconcile to the balance.',
        );
    }

    public function testAFailedIssueRollsBackCompletely(): void
    {
        [$productId, $warehouseId] = $this->givenProductWithStock(5);

        try {
            $this->serviceOn($this->pdo)->issue(
                new MovementCommand($productId, $warehouseId, 99, $this->anyUserId())
            );
            self::fail('Expected the issue to be rejected.');
        } catch (InsufficientStockException) {
            // expected
        }

        self::assertSame(5, $this->balance($productId, $warehouseId), 'Balance unchanged.');
        self::assertSame(0, $this->ledgerSum($productId, $warehouseId), 'No ledger row written.');
        self::assertFalse($this->pdo->inTransaction(), 'The transaction must have been rolled back.');
    }

    /**
     * ARCH-02, part 1: the lock is real.
     *
     * Connection A opens a transaction and takes the row lock. Connection B then
     * tries to take the same lock and must NOT be allowed to proceed. Its
     * innodb_lock_wait_timeout is set to 2 seconds so the test fails fast
     * instead of hanging; the timeout error is the proof that B was blocked.
     *
     * No threads are needed — the brief explicitly allows a controlled scenario.
     */
    public function testASecondTransactionCannotReadTheSameStockRowWhileItIsLocked(): void
    {
        [$productId, $warehouseId] = $this->givenProductWithStock(10);

        $connectionA = $this->connect();
        $connectionB = $this->connect();
        $connectionB->exec('SET SESSION innodb_lock_wait_timeout = 2');

        $repositoryA = new MySqlStockRepository($connectionA);
        $repositoryB = new MySqlStockRepository($connectionB);

        $connectionA->beginTransaction();
        self::assertSame(10, $repositoryA->readBalanceForUpdate($productId, $warehouseId));

        $connectionB->beginTransaction();
        $blocked = false;
        try {
            $repositoryB->readBalanceForUpdate($productId, $warehouseId);
        } catch (\PDOException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout');
        }
        $connectionB->rollBack();
        $connectionA->rollBack();

        self::assertTrue(
            $blocked,
            'A locking read must block a second transaction; if this passes without blocking, '
            . 'the FOR UPDATE clause or the unique index is missing.',
        );
    }

    /**
     * ARCH-02, part 2: the full interleaved scenario — no oversell.
     *
     * Written as a genuine interleaving rather than "A finishes, then B starts".
     * A sequential version passes even with FOR UPDATE removed, so it would
     * prove nothing. This one depends on the lock at step 3.
     *
     *   1. A opens a transaction and takes the lock, seeing 10.
     *   2. B opens a transaction and reads a snapshot, also seeing 10. Under
     *      REPEATABLE READ that stale value is what a naive implementation
     *      would base its decision on.
     *   3. B attempts its own locking read and is BLOCKED by A.
     *   4. A issues 8 and commits, leaving 2.
     *   5. B retries: its locking read returns the committed 2, so its request
     *      for 8 is refused instead of driving stock to -6.
     */
    public function testConcurrentIssuesCannotOversell(): void
    {
        [$productId, $warehouseId] = $this->givenProductWithStock(10);
        $userId = $this->anyUserId();

        $connectionA = $this->connect();
        $connectionB = $this->connect();
        $connectionB->exec('SET SESSION innodb_lock_wait_timeout = 2');

        // 1. A takes the lock.
        $connectionA->beginTransaction();
        $repositoryA = new MySqlStockRepository($connectionA);
        self::assertSame(10, $repositoryA->readBalanceForUpdate($productId, $warehouseId));

        // 2. B reads the stale snapshot that would cause an oversell.
        $connectionB->beginTransaction();
        $snapshot = $connectionB->prepare(
            'SELECT quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?'
        );
        $snapshot->execute([$productId, $warehouseId]);
        self::assertSame(10, (int) $snapshot->fetchColumn(), 'B sees the pre-commit balance.');

        // 3. B is blocked when it tries to take the lock for its own decision.
        $blocked = false;
        try {
            (new MySqlStockRepository($connectionB))->readBalanceForUpdate($productId, $warehouseId);
        } catch (PDOException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout');
        }
        $connectionB->rollBack();
        self::assertTrue($blocked, 'B must be blocked while A holds the lock.');

        // 4. A completes its issue of 8 and commits.
        $repositoryA->adjust($productId, $warehouseId, -8);
        (new MySqlStockLedgerRepository($connectionA))->append(new LedgerEntry(
            null,
            $productId,
            $warehouseId,
            MovementType::Issue,
            -8,
            ReferenceType::SalesOrder,
            null,
            $userId,
        ));
        $connectionA->commit();
        self::assertSame(2, $this->balance($productId, $warehouseId));

        // 5. B retries and is refused on the committed value.
        $rejected = false;
        try {
            $this->serviceOn($connectionB)->issue(
                new MovementCommand($productId, $warehouseId, 8, $userId, ReferenceType::SalesOrder, null)
            );
        } catch (InsufficientStockException $e) {
            $rejected = true;
            self::assertSame(2, $e->available, 'B decides on the committed balance, not its snapshot.');
        }

        self::assertTrue($rejected, 'The second issue must be refused, not allowed to oversell.');
        self::assertSame(2, $this->balance($productId, $warehouseId), 'Stock never goes negative.');
        self::assertSame(-8, $this->ledgerSum($productId, $warehouseId), 'Exactly one issue recorded.');
    }

    /**
     * The database refuses negative stock even if the service is bypassed.
     * This is the second line of defence described in ADR-002.
     */
    public function testTheDatabaseItselfRefusesNegativeStock(): void
    {
        [$productId, $warehouseId] = $this->givenProductWithStock(3);

        $this->expectException(RuntimeException::class);
        $statement = $this->pdo->prepare(
            'UPDATE product_stocks SET quantity = quantity - 10 WHERE product_id = ? AND warehouse_id = ?'
        );
        $statement->execute([$productId, $warehouseId]);
    }

    public function testTheLedgerReconcilesAfterAMixOfMovements(): void
    {
        [$productId, $warehouseId] = $this->givenProductWithStock(0);
        $service = $this->serviceOn($this->pdo);
        $userId = $this->anyUserId();

        $service->receive(new MovementCommand($productId, $warehouseId, 50, $userId, ReferenceType::PurchaseOrder));
        $service->issue(new MovementCommand($productId, $warehouseId, 12, $userId, ReferenceType::SalesOrder));
        $service->issue(new MovementCommand($productId, $warehouseId, 8, $userId, ReferenceType::SalesOrder));
        $service->receive(new MovementCommand($productId, $warehouseId, 5, $userId, ReferenceType::PurchaseOrder));

        self::assertSame(35, $this->balance($productId, $warehouseId), '50 - 12 - 8 + 5');
        self::assertSame(
            $this->balance($productId, $warehouseId),
            $this->ledgerSum($productId, $warehouseId),
            'SUM(stock_ledger) must equal product_stocks.quantity.',
        );
    }
}
