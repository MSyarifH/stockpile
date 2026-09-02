<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuthenticatedUser;
use App\Entity\BusinessPartner;
use App\Entity\PartnerType;
use App\Entity\Role;
use App\Repository\BusinessPartnerRepository;
use App\Service\Exception\AuthorizationException;
use App\Support\Exception\HttpException;
use App\Support\Exception\ValidationException;

/**
 * Suppliers and customers share this service because §1.3 gives them identical
 * fields and §1.3's "Keputusan data" gives them identical rules (deactivate,
 * never delete). Writing the same rules twice would let them drift apart.
 */
final class BusinessPartnerService
{
    public function __construct(private readonly BusinessPartnerRepository $partners)
    {
    }

    /** @return list<BusinessPartner> */
    public function list(PartnerType $type, bool $activeOnly = false): array
    {
        return $this->partners->all($type, $activeOnly);
    }

    public function find(PartnerType $type, int $id): BusinessPartner
    {
        $partner = $this->partners->findById($type, $id);
        if ($partner === null) {
            throw HttpException::notFound(sprintf('That %s does not exist.', strtolower($type->label())));
        }

        return $partner;
    }

    /** @param array{name:string,contact:string,address:string,is_active:bool} $data */
    public function create(AuthenticatedUser $actor, PartnerType $type, array $data): int
    {
        $this->assertAdmin($actor);
        $this->assertName($data['name']);

        return $this->partners->create(new BusinessPartner(
            null,
            $type,
            trim($data['name']),
            trim($data['contact']),
            trim($data['address']),
            $data['is_active'],
        ));
    }

    /** @param array{name:string,contact:string,address:string,is_active:bool} $data */
    public function update(AuthenticatedUser $actor, PartnerType $type, int $id, array $data): void
    {
        $this->assertAdmin($actor);
        $this->find($type, $id);
        $this->assertName($data['name']);

        $this->partners->update($id, new BusinessPartner(
            $id,
            $type,
            trim($data['name']),
            trim($data['contact']),
            trim($data['address']),
            $data['is_active'],
        ));
    }

    public function setActive(AuthenticatedUser $actor, PartnerType $type, int $id, bool $isActive): void
    {
        $this->assertAdmin($actor);
        $this->find($type, $id);
        $this->partners->setActive($type, $id, $isActive);
    }

    private function assertName(string $name): void
    {
        if (trim($name) === '') {
            throw new ValidationException(['name' => 'Name is required.']);
        }
    }

    private function assertAdmin(AuthenticatedUser $actor): void
    {
        if ($actor->role !== Role::Admin) {
            throw new AuthorizationException('Only an Admin may manage suppliers and customers.');
        }
    }
}
