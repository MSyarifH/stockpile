<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Suppliers and customers carry identical data (§1.3 lists them on one row:
 * name, contact, address, active flag) and identical rules — deactivate, never
 * delete. They remain SEPARATE TABLES because a purchase order references a
 * supplier and a sales order references a customer; merging them would allow a
 * sales order to point at a supplier, which the foreign keys currently prevent.
 *
 * The shared shape is modelled once here and distinguished by PartnerType, so
 * the rules exist in one place rather than being written twice and drifting.
 */
final class BusinessPartner
{
    public function __construct(
        public readonly ?int $id,
        public readonly PartnerType $type,
        public readonly string $name,
        public readonly string $contact,
        public readonly string $address,
        public readonly bool $isActive,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(PartnerType $type, array $row): self
    {
        return new self(
            (int) $row['id'],
            $type,
            (string) $row['name'],
            (string) $row['contact'],
            (string) $row['address'],
            (bool) $row['is_active'],
        );
    }
}
