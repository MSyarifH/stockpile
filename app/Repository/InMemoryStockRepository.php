<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Test double. Single-threaded, so there is nothing to lock — but it honours the
 * same contract, which is what lets the service's RULES be tested here while the
 * concurrency MECHANISM is proven separately by an integration test against real
 * MySQL (TEST-02).
 */
final class InMemoryStockRepository implements StockRepository
{
    /** @var array<string,int> "productId:warehouseId" => quantity */
    private array $balances = [];

    /** @var list<string> records the order rows were locked in, for deadlock-ordering tests */
    public array $lockOrder = [];

    /** @param array<string,int> $balances keyed "productId:warehouseId" */
    public function __construct(array $balances = [])
    {
        $this->balances = $balances;
    }

    private function key(int $productId, int $warehouseId): string
    {
        return $productId . ':' . $warehouseId;
    }

    public function readBalanceForUpdate(int $productId, int $warehouseId): int
    {
        $key = $this->key($productId, $warehouseId);
        $this->lockOrder[] = $key;

        return $this->balances[$key] ?? 0;
    }

    public function adjust(int $productId, int $warehouseId, int $delta): void
    {
        $key = $this->key($productId, $warehouseId);
        $this->balances[$key] = ($this->balances[$key] ?? 0) + $delta;
    }

    public function exists(int $productId, int $warehouseId): bool
    {
        return array_key_exists($this->key($productId, $warehouseId), $this->balances);
    }

    public function ensureRow(int $productId, int $warehouseId): void
    {
        $this->balances[$this->key($productId, $warehouseId)] ??= 0;
    }

    /** Test helper. */
    public function balance(int $productId, int $warehouseId): int
    {
        return $this->balances[$this->key($productId, $warehouseId)] ?? 0;
    }
}
