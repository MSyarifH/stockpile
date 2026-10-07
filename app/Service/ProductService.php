<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuthenticatedUser;
use App\Entity\Product;
use App\Entity\Role;
use App\Entity\StockLevel;
use App\Repository\ProductFilter;
use App\Repository\ProductRepository;
use App\Service\Exception\AuthorizationException;
use App\Support\Exception\HttpException;
use App\Support\Exception\ValidationException;
use App\Support\Page;
use App\Support\TransactionManager;

/**
 * PRD-01 and the read side of WH-01.
 *
 * Nothing here writes stock quantities: that is StockService's sole
 * responsibility (Phase 3). This service only ensures a product HAS a stock row
 * in every warehouse, initialised to zero.
 */
final class ProductService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly TransactionManager $transactions,
    ) {
    }

    /** @return list<Product> */
    public function list(bool $activeOnly = false): array
    {
        return $this->products->all($activeOnly);
    }

    /**
     * Search, filter and paginate the catalogue (FIND-01).
     *
     * @return Page<Product>
     */
    public function search(ProductFilter $filter, int $page): Page
    {
        return $this->products->paginate($filter, $page);
    }

    public function find(int $id): Product
    {
        $product = $this->products->findById($id);
        if ($product === null) {
            throw HttpException::notFound('That product does not exist.');
        }

        return $product;
    }

    /** Used by the JSON availability endpoint (API-01). */
    public function findBySku(string $sku): ?Product
    {
        return $this->products->findBySku(strtoupper(trim($sku)));
    }

    /** @return list<StockLevel> */
    public function stockLevels(int $productId): array
    {
        return $this->products->stockLevels($productId);
    }

    /** @return list<Product> */
    public function lowStock(): array
    {
        return $this->products->lowStock();
    }

    /**
     * @param array{sku:string,name:string,category_id:int,unit:string,purchase_price:float,
     *              selling_price:float,reorder_point:int,is_active:bool,image_path?:string|null} $data
     */
    public function create(AuthenticatedUser $actor, array $data): int
    {
        $this->assertAdmin($actor);
        $this->assertValues($data);
        $this->assertSkuAvailable($data['sku'], null);

        return $this->transactions->transactional(function () use ($data): int {
            $id = $this->products->create(new Product(
                null,
                strtoupper(trim($data['sku'])),
                $data['name'],
                $data['category_id'],
                $data['unit'],
                $data['purchase_price'],
                $data['selling_price'],
                $data['reorder_point'],
                $data['image_path'] ?? null,
                $data['is_active'],
            ));

            // A product with no stock row in a warehouse is indistinguishable
            // from one with zero stock, but only the second can be received into.
            $this->products->createStockRowsForAllWarehouses($id);

            return $id;
        });
    }

    /**
     * @param array{sku:string,name:string,category_id:int,unit:string,purchase_price:float,
     *              selling_price:float,reorder_point:int,is_active:bool} $data
     */
    public function update(AuthenticatedUser $actor, int $id, array $data): void
    {
        $this->assertAdmin($actor);
        $existing = $this->find($id);
        $this->assertValues($data);
        $this->assertSkuAvailable($data['sku'], $id);

        // D9 / §1.3: a product is never deleted, so deactivation is the only way
        // to retire one. Blocking deactivation would leave no way out at all.
        $this->products->update($id, new Product(
            $id,
            strtoupper(trim($data['sku'])),
            $data['name'],
            $data['category_id'],
            $data['unit'],
            $data['purchase_price'],
            $data['selling_price'],
            $data['reorder_point'],
            $existing->imagePath,
            $data['is_active'],
        ));
    }

    public function setActive(AuthenticatedUser $actor, int $id, bool $isActive): void
    {
        $this->assertAdmin($actor);
        $this->find($id);
        $this->products->setActive($id, $isActive);
    }

    public function setImage(AuthenticatedUser $actor, int $id, ?string $imagePath): void
    {
        $this->assertAdmin($actor);
        $this->find($id);
        $this->products->updateImagePath($id, $imagePath);
    }

    /**
     * There is no delete operation, deliberately. §1.3 states products are
     * deactivated rather than removed, and a hard delete could orphan an order
     * line or a ledger entry. Documented as decision D9.
     */
    public function assertCannotDelete(): never
    {
        throw new AuthorizationException('Products are deactivated, never deleted.');
    }

    /** @param array<string,mixed> $data */
    private function assertValues(array $data): void
    {
        $errors = [];

        foreach (['purchase_price' => 'Purchase price', 'selling_price' => 'Selling price'] as $key => $label) {
            if ((float) $data[$key] < 0) {
                $errors[$key] = $label . ' cannot be negative.';
            }
        }

        if ((int) $data['reorder_point'] < 0) {
            $errors['reorder_point'] = 'Reorder point cannot be negative.';
        }

        if (trim((string) $data['sku']) === '') {
            $errors['sku'] = 'SKU is required.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    private function assertSkuAvailable(string $sku, ?int $exceptId): void
    {
        if ($this->products->skuExists(strtoupper(trim($sku)), $exceptId)) {
            throw new ValidationException(['sku' => 'That SKU is already used by another product.']);
        }
    }

    private function assertAdmin(AuthenticatedUser $actor): void
    {
        if ($actor->role !== Role::Admin) {
            throw new AuthorizationException('Only an Admin may change the product catalogue.');
        }
    }
}
