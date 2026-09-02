<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BusinessPartner;
use App\Entity\PartnerType;
use PDO;

/**
 * Serves both suppliers and customers. They are separate tables (a purchase
 * order must not be able to reference a customer), but the columns and the
 * rules are identical, so one repository handles both and the table name comes
 * from PartnerType.
 *
 * The table name is interpolated into SQL, which is normally forbidden. It is
 * safe here because it comes from a match() over an enum — never from user
 * input — so only two literal strings can ever appear. Every VALUE is still
 * bound as a parameter.
 */
final class BusinessPartnerRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<BusinessPartner> */
    public function all(PartnerType $type, bool $activeOnly = false): array
    {
        $sql = sprintf('SELECT id, name, contact, address, is_active FROM %s', $type->table());
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name';

        $statement = $this->pdo->query($sql);
        $rows = $statement === false ? [] : $statement->fetchAll();

        return array_map(
            static fn (array $row): BusinessPartner => BusinessPartner::fromRow($type, $row),
            $rows,
        );
    }

    public function findById(PartnerType $type, int $id): ?BusinessPartner
    {
        $statement = $this->pdo->prepare(
            sprintf('SELECT id, name, contact, address, is_active FROM %s WHERE id = ?', $type->table())
        );
        $statement->execute([$id]);
        $row = $statement->fetch();

        return is_array($row) ? BusinessPartner::fromRow($type, $row) : null;
    }

    public function create(BusinessPartner $partner): int
    {
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (name, contact, address, is_active) VALUES (?, ?, ?, ?)',
            $partner->type->table(),
        ));
        $statement->execute([$partner->name, $partner->contact, $partner->address, $partner->isActive ? 1 : 0]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, BusinessPartner $partner): void
    {
        $statement = $this->pdo->prepare(sprintf(
            'UPDATE %s SET name = ?, contact = ?, address = ?, is_active = ? WHERE id = ?',
            $partner->type->table(),
        ));
        $statement->execute([
            $partner->name,
            $partner->contact,
            $partner->address,
            $partner->isActive ? 1 : 0,
            $id,
        ]);
    }

    public function setActive(PartnerType $type, int $id, bool $isActive): void
    {
        $statement = $this->pdo->prepare(
            sprintf('UPDATE %s SET is_active = ? WHERE id = ?', $type->table())
        );
        $statement->execute([$isActive ? 1 : 0, $id]);
    }
}
