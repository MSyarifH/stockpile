<?php

declare(strict_types=1);

namespace App\Entity;

final class Warehouse
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $location,
        public readonly bool $isActive,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['location'],
            (bool) $row['is_active'],
        );
    }
}
