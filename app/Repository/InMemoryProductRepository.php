<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\StockLevel;

/**
 * Test double. Holds products in an array and per-warehouse balances in a map,
 * so the same rules can be exercised with no database.
 */
final class InMemoryProductRepository implements ProductRepository
{
    /** @var array<int,Product> */
    private array $products = [];

    /** @var array<int,array<int,int>> productId => warehouseId => quantity */
    private array $stock = [];

    /** @var array<int,true> */
    private array $usedOnOrder = [];

    private int $nextId = 1;

    /** @param list<Product> $products */
    public function __construct(array $products = [])
    {
        foreach ($products as $product) {
            $this->create($product);
        }
    }

    /** Test helper: set a per-warehouse balance. */
    public function setStock(int $productId, int $warehouseId, int $quantity): void
    {
        $this->stock[$productId][$warehouseId] = $quantity;
    }

    /** Test helper: mark a product as already referenced by an order line. */
    public function markUsedOnOrder(int $productId): void
    {
        $this->usedOnOrder[$productId] = true;
    }

    private function withTotals(Product $product): Product
    {
        $total = array_sum($this->stock[(int) $product->id] ?? []);

        return new Product(
            $product->id,
            $product->sku,
            $product->name,
            $product->categoryId,
            $product->unit,
            $product->purchasePrice,
            $product->sellingPrice,
            $product->reorderPoint,
            $product->imagePath,
            $product->isActive,
            $product->categoryName,
            (int) $total,
        );
    }

    public function all(bool $activeOnly = false): array
    {
        $products = array_values($this->products);
        if ($activeOnly) {
            $products = array_values(array_filter($products, static fn (Product $p): bool => $p->isActive));
        }

        return array_map(fn (Product $p): Product => $this->withTotals($p), $products);
    }

    public function findById(int $id): ?Product
    {
        $product = $this->products[$id] ?? null;

        return $product === null ? null : $this->withTotals($product);
    }

    public function findBySku(string $sku): ?Product
    {
        foreach ($this->products as $product) {
            if (strcasecmp($product->sku, $sku) === 0) {
                return $this->withTotals($product);
            }
        }

        return null;
    }

    public function skuExists(string $sku, ?int $exceptId = null): bool
    {
        foreach ($this->products as $id => $product) {
            if (strcasecmp($product->sku, $sku) === 0 && $id !== $exceptId) {
                return true;
            }
        }

        return false;
    }

    public function create(Product $product): int
    {
        $id = $product->id ?? $this->nextId;
        $this->nextId = max($this->nextId, $id + 1);

        $this->products[$id] = new Product(
            $id,
            $product->sku,
            $product->name,
            $product->categoryId,
            $product->unit,
            $product->purchasePrice,
            $product->sellingPrice,
            $product->reorderPoint,
            $product->imagePath,
            $product->isActive,
            $product->categoryName,
        );

        return $id;
    }

    public function update(int $id, Product $product): void
    {
        $existing = $this->products[$id] ?? null;
        $this->products[$id] = new Product(
            $id,
            $product->sku,
            $product->name,
            $product->categoryId,
            $product->unit,
            $product->purchasePrice,
            $product->sellingPrice,
            $product->reorderPoint,
            $existing?->imagePath,
            $product->isActive,
            $product->categoryName,
        );
    }

    public function setActive(int $id, bool $isActive): void
    {
        $p = $this->products[$id] ?? null;
        if ($p === null) {
            return;
        }
        $this->products[$id] = new Product(
            $id,
            $p->sku,
            $p->name,
            $p->categoryId,
            $p->unit,
            $p->purchasePrice,
            $p->sellingPrice,
            $p->reorderPoint,
            $p->imagePath,
            $isActive,
            $p->categoryName,
        );
    }

    public function updateImagePath(int $id, ?string $imagePath): void
    {
        $p = $this->products[$id] ?? null;
        if ($p === null) {
            return;
        }
        $this->products[$id] = new Product(
            $id,
            $p->sku,
            $p->name,
            $p->categoryId,
            $p->unit,
            $p->purchasePrice,
            $p->sellingPrice,
            $p->reorderPoint,
            $imagePath,
            $p->isActive,
            $p->categoryName,
        );
    }

    public function isUsedOnAnyOrder(int $id): bool
    {
        return isset($this->usedOnOrder[$id]);
    }

    public function stockLevels(int $productId): array
    {
        $levels = [];
        foreach ($this->stock[$productId] ?? [] as $warehouseId => $quantity) {
            $levels[] = new StockLevel($productId, $warehouseId, 'Warehouse ' . $warehouseId, $quantity);
        }

        return $levels;
    }

    public function createStockRowsForAllWarehouses(int $productId): void
    {
        $this->stock[$productId] ??= [];
    }

    public function lowStock(): array
    {
        $low = [];
        foreach ($this->products as $product) {
            if (!$product->isActive) {
                continue;
            }
            $withTotals = $this->withTotals($product);
            if ($withTotals->isLowStock()) {
                $low[] = $withTotals;
            }
        }

        return $low;
    }
}
