<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LedgerEntry;
use App\Entity\MovementCommand;
use App\Entity\MovementType;
use App\Repository\StockLedgerRepository;
use App\Repository\StockRepository;
use App\Service\Exception\InsufficientStockException;
use App\Support\TransactionManager;

/**
 * The ONLY class permitted to change product_stocks or write stock_ledger.
 *
 * Purchase and sales services do not touch stock; they call this. That single
 * choke point is what makes the system's core invariant defensible:
 *
 *     SUM(stock_ledger.quantity) == product_stocks.quantity   per (product, warehouse)
 *
 * ARCH-02 is satisfied here. See docs/architecture/adr-002-oversell-prevention.md
 * for why pessimistic locking was chosen over a conditional UPDATE.
 */
final class StockService
{
    public function __construct(
        private readonly StockRepository $stock,
        private readonly StockLedgerRepository $ledger,
        private readonly TransactionManager $transactions,
    ) {
    }

    /** Goods receipt for a single line. */
    public function receive(MovementCommand $command): void
    {
        $this->receiveAll([$command]);
    }

    /** Goods issue for a single line. */
    public function issue(MovementCommand $command): void
    {
        $this->issueAll([$command]);
    }

    /** @param list<MovementCommand> $commands */
    public function receiveAll(array $commands): void
    {
        $this->apply($commands, MovementType::Receipt);
    }

    /**
     * Goods issue for a whole order.
     *
     * All lines succeed or none do: an order cannot be half-shipped, so every
     * line is validated before any line is written.
     *
     * @param list<MovementCommand> $commands
     * @throws InsufficientStockException
     */
    public function issueAll(array $commands): void
    {
        $this->apply($commands, MovementType::Issue);
    }

    /**
     * @param list<MovementCommand> $commands
     */
    private function apply(array $commands, MovementType $type): void
    {
        if ($commands === []) {
            return;
        }

        $this->transactions->transactional(function () use ($commands, $type): void {
            $ordered = $this->inDeterministicLockOrder($commands);

            // ---- phase 1: lock every affected row, then decide -------------
            //
            // Locking first and deciding afterwards is the whole point. Under
            // REPEATABLE READ a plain read would see a snapshot from before a
            // competing transaction started, so two issues could both conclude
            // there was enough stock.
            //
            // Several lines of one order may hit the same stock row, so the
            // running total is accumulated per row rather than compared line
            // by line — otherwise 2 lines of 6 against a balance of 10 would
            // both pass while together they overdraw it.
            $projected = [];
            foreach ($ordered as $command) {
                $key = $command->stockKey();

                if (!array_key_exists($key, $projected)) {
                    $this->stock->ensureRow($command->productId, $command->warehouseId);
                    $projected[$key] = $this->stock->readBalanceForUpdate(
                        $command->productId,
                        $command->warehouseId,
                    );
                }

                $projected[$key] += $type->signedDelta($command->quantity);

                if ($type->reducesStock() && $projected[$key] < 0) {
                    // Thrown inside the transaction, so the TransactionManager
                    // rolls everything back. Nothing partial can survive.
                    throw new InsufficientStockException(
                        $command->productId,
                        $command->warehouseId,
                        $command->quantity,
                        $projected[$key] + $command->quantity,
                    );
                }
            }

            // ---- phase 2: write, now that every line is known to be valid ---
            foreach ($ordered as $command) {
                $delta = $type->signedDelta($command->quantity);

                $this->stock->adjust($command->productId, $command->warehouseId, $delta);

                // Balance and history are written in the same transaction, which
                // is what keeps the invariant true even if the request dies here.
                $this->ledger->append(new LedgerEntry(
                    null,
                    $command->productId,
                    $command->warehouseId,
                    $type,
                    $delta,
                    $command->referenceType,
                    $command->referenceId,
                    $command->performedBy,
                ));
            }
        });
    }

    /**
     * Locks are always acquired in the same order, sorted by product then
     * warehouse.
     *
     * Without this, two multi-line orders touching the same products in
     * opposite order deadlock: order A holds product 1 and waits for product 2
     * while order B holds product 2 and waits for product 1. MySQL breaks the
     * tie by killing one transaction after innodb_lock_wait_timeout. Sorting
     * makes that cycle impossible, because no transaction can hold a later row
     * while waiting for an earlier one.
     *
     * @param list<MovementCommand> $commands
     * @return list<MovementCommand>
     */
    private function inDeterministicLockOrder(array $commands): array
    {
        usort(
            $commands,
            static fn (MovementCommand $a, MovementCommand $b): int
                => [$a->productId, $a->warehouseId] <=> [$b->productId, $b->warehouseId],
        );

        return $commands;
    }
}
