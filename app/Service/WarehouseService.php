<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuthenticatedUser;
use App\Entity\Role;
use App\Entity\Warehouse;
use App\Repository\WarehouseRepository;
use App\Service\Exception\AuthorizationException;
use App\Support\Exception\HttpException;
use App\Support\Exception\ValidationException;
use App\Support\TransactionManager;

final class WarehouseService
{
    public function __construct(
        private readonly WarehouseRepository $warehouses,
        private readonly TransactionManager $transactions,
    ) {
    }

    /** @return list<Warehouse> */
    public function list(bool $activeOnly = false): array
    {
        return $this->warehouses->all($activeOnly);
    }

    public function find(int $id): Warehouse
    {
        $warehouse = $this->warehouses->findById($id);
        if ($warehouse === null) {
            throw HttpException::notFound('That warehouse does not exist.');
        }

        return $warehouse;
    }

    public function create(AuthenticatedUser $actor, string $name, string $location, bool $isActive): int
    {
        $this->assertAdmin($actor);
        $this->assertNameAvailable($name, null);

        return $this->transactions->transactional(function () use ($name, $location, $isActive): int {
            $id = $this->warehouses->create(new Warehouse(null, trim($name), trim($location), $isActive));
            // Every existing product needs a zero row here, or it could never be
            // received into this warehouse (WH-01).
            $this->warehouses->createStockRowsForAllProducts($id);

            return $id;
        });
    }

    public function update(AuthenticatedUser $actor, int $id, string $name, string $location, bool $isActive): void
    {
        $this->assertAdmin($actor);
        $this->find($id);
        $this->assertNameAvailable($name, $id);

        $this->warehouses->update($id, new Warehouse($id, trim($name), trim($location), $isActive));
    }

    public function setActive(AuthenticatedUser $actor, int $id, bool $isActive): void
    {
        $this->assertAdmin($actor);
        $this->find($id);
        $this->warehouses->setActive($id, $isActive);
    }

    private function assertNameAvailable(string $name, ?int $exceptId): void
    {
        if ($this->warehouses->nameExists(trim($name), $exceptId)) {
            throw new ValidationException(['name' => 'A warehouse with that name already exists.']);
        }
    }

    private function assertAdmin(AuthenticatedUser $actor): void
    {
        if ($actor->role !== Role::Admin) {
            throw new AuthorizationException('Only an Admin may manage warehouses.');
        }
    }
}
