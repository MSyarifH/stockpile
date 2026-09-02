<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\StockLevel;
use App\Support\Page;

/**
 * Interface + fake, because ProductService branches on this data: the low-stock
 * rule and the "used on an order, so deactivate only" rule both need to be
 * unit-tested without a database (ADR-001).
 */
interface ProductRepository
{
    /** @return list<Product> */
    public function all(bool $activeOnly = false): array;

    /**
     * Search, filter and paginate (FIND-01).
     *
     * @return Page<Product>
     */
    public function paginate(ProductFilter $filter, int $page): Page;

    public function findById(int $id): ?Product;

    public function findBySku(string $sku): ?Product;

    public function skuExists(string $sku, ?int $exceptId = null): bool;

    public function create(Product $product): int;

    public function update(int $id, Product $product): void;

    public function setActive(int $id, bool $isActive): void;

    public function updateImagePath(int $id, ?string $imagePath): void;

    /** True once the product appears on any purchase or sales order line. */
    public function isUsedOnAnyOrder(int $id): bool;

    /** @return list<StockLevel> per-warehouse breakdown for one product (WH-01) */
    public function stockLevels(int $productId): array;

    /** Ensures a stock row exists for this product in every warehouse. */
    public function createStockRowsForAllWarehouses(int $productId): void;

    /** @return list<Product> products at or below their reorder point (JOB-01, DASH-01) */
    public function lowStock(): array;
}
