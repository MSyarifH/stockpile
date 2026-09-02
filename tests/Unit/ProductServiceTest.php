<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\AuthenticatedUser;
use App\Entity\Product;
use App\Entity\Role;
use App\Repository\InMemoryProductRepository;
use App\Service\Exception\AuthorizationException;
use App\Service\ProductService;
use App\Support\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeTransactionManager;

/**
 * Logic area 3 — catalogue rules and the low-stock calculation (PRD-01, WH-01).
 *
 * No database: InMemoryProductRepository plus a fake transaction boundary.
 */
final class ProductServiceTest extends TestCase
{
    private InMemoryProductRepository $repository;
    private FakeTransactionManager $transactions;
    private ProductService $service;

    protected function setUp(): void
    {
        $this->repository = new InMemoryProductRepository([
            $this->product(1, 'SKU-001', 'Existing Product', reorderPoint: 10),
        ]);
        $this->transactions = new FakeTransactionManager();
        $this->service = new ProductService($this->repository, $this->transactions);
    }

    private function product(int $id, string $sku, string $name, int $reorderPoint = 0, bool $active = true): Product
    {
        return new Product($id, $sku, $name, 1, 'pcs', 1000.0, 1500.0, $reorderPoint, null, $active);
    }

    private function admin(): AuthenticatedUser
    {
        return new AuthenticatedUser(1, 'Admin', Role::Admin);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array{sku:string,name:string,category_id:int,unit:string,purchase_price:float,
     *               selling_price:float,reorder_point:int,is_active:bool}
     */
    private function validData(array $overrides = []): array
    {
        /** @var array{sku:string,name:string,category_id:int,unit:string,purchase_price:float,
         *             selling_price:float,reorder_point:int,is_active:bool} $data */
        $data = $overrides + [
            'sku' => 'NEW-001',
            'name' => 'New Product',
            'category_id' => 1,
            'unit' => 'pcs',
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'reorder_point' => 5,
            'is_active' => true,
        ];

        return $data;
    }

    public function testSalesCannotCreateAProduct(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->create(new AuthenticatedUser(2, 'Seller', Role::Sales), $this->validData());
    }

    public function testWarehouseStaffCannotCreateAProduct(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->create(new AuthenticatedUser(3, 'Storeman', Role::WarehouseStaff), $this->validData());
    }

    public function testDuplicateSkuIsRejectedRegardlessOfCase(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->admin(), $this->validData(['sku' => 'sku-001']));
    }

    public function testNegativePurchasePriceIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->admin(), $this->validData(['purchase_price' => -1.0]));
    }

    public function testNegativeReorderPointIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->admin(), $this->validData(['reorder_point' => -5]));
    }

    public function testCreatingAProductNormalisesTheSkuAndOpensStockRows(): void
    {
        $id = $this->service->create($this->admin(), $this->validData(['sku' => ' new-002 ']));

        $created = $this->repository->findById($id);
        self::assertNotNull($created);
        self::assertSame('NEW-002', $created->sku, 'SKU is trimmed and upper-cased before storage.');

        // The whole create must be one atomic unit: the product row and its
        // per-warehouse stock rows either both exist or neither does.
        self::assertSame(1, $this->transactions->started);
        self::assertSame(1, $this->transactions->committed);
        self::assertSame(0, $this->transactions->rolledBack);
    }

    /**
     * The brief defines low stock as "di bawah reorder point". A product sitting
     * exactly ON its reorder point is the moment to reorder, so the boundary is
     * inclusive. Pinning it in a test stops the definition drifting between the
     * product list, the dashboard and the scheduled job.
     */
    public function testLowStockBoundaryIsInclusive(): void
    {
        $this->repository->setStock(1, 1, 10);   // reorder point is 10
        $atThreshold = $this->repository->findById(1);
        self::assertNotNull($atThreshold);
        self::assertTrue($atThreshold->isLowStock(), 'Stock equal to the reorder point counts as low.');

        $this->repository->setStock(1, 1, 11);
        $above = $this->repository->findById(1);
        self::assertNotNull($above);
        self::assertFalse($above->isLowStock());
    }

    public function testLowStockSumsAcrossWarehousesNotPerWarehouse(): void
    {
        // 6 + 6 = 12, above the reorder point of 10, even though neither
        // warehouse alone would cover an order of 10.
        $this->repository->setStock(1, 1, 6);
        $this->repository->setStock(1, 2, 6);

        self::assertSame([], $this->repository->lowStock());

        $this->repository->setStock(1, 2, 2);   // total now 8
        self::assertCount(1, $this->repository->lowStock());
    }

    public function testInactiveProductsAreExcludedFromLowStock(): void
    {
        $this->repository->create($this->product(2, 'SKU-OLD', 'Retired', reorderPoint: 50, active: false));
        $this->repository->setStock(2, 1, 0);

        foreach ($this->repository->lowStock() as $product) {
            self::assertNotSame('SKU-OLD', $product->sku, 'A deactivated product should not be reordered.');
        }
    }
}
