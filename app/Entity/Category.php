<?php

declare(strict_types=1);

namespace App\Entity;

final class Category
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $description,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self((int) $row['id'], (string) $row['name'], (string) $row['description']);
    }
}
