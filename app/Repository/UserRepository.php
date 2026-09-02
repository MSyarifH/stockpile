<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;

/**
 * Interface, not a concrete class, because authentication carries real business
 * rules (an inactive account may not sign in; the password must verify) and
 * TEST-01 requires those rules to be testable with no database.
 */
interface UserRepository
{
    public function findByEmail(string $email): ?User;

    public function findById(int $id): ?User;

    public function emailExists(string $email, ?int $exceptId = null): bool;

    public function create(User $user): int;

    public function update(int $id, User $user): void;

    /** Password is changed separately so it is never rewritten by accident on a profile edit. */
    public function updatePassword(int $id, string $passwordHash): void;

    public function setActive(int $id, bool $isActive): void;

    /** @return list<User> */
    public function all(): array;
}
