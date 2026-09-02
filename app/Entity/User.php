<?php

declare(strict_types=1);

namespace App\Entity;

final class User
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $passwordHash,
        public readonly Role $role,
        public readonly bool $isActive,
    ) {
    }

    public function toAuthenticated(): AuthenticatedUser
    {
        return new AuthenticatedUser((int) $this->id, $this->name, $this->role);
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['email'],
            (string) $row['password_hash'],
            Role::from((string) $row['role']),
            (bool) $row['is_active'],
        );
    }
}
