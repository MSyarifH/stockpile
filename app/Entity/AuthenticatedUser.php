<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * The identity carried through a request. Deliberately does NOT contain the
 * password hash: nothing downstream of authentication has any use for it.
 */
final class AuthenticatedUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly Role $role,
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function is(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }
}
