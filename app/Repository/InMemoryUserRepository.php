<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;

/**
 * Test double used by unit tests. Same contract, no database.
 *
 * This is the second implementation ARCH-01 requires, and it is what allows the
 * authentication rules to be tested without MySQL running.
 */
final class InMemoryUserRepository implements UserRepository
{
    /** @var array<int,User> */
    private array $users = [];

    private int $nextId = 1;

    /** @param list<User> $users */
    public function __construct(array $users = [])
    {
        foreach ($users as $user) {
            $this->create($user);
        }
    }

    public function findByEmail(string $email): ?User
    {
        foreach ($this->users as $user) {
            if (strcasecmp($user->email, $email) === 0) {
                return $user;
            }
        }
        return null;
    }

    public function findById(int $id): ?User
    {
        return $this->users[$id] ?? null;
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        foreach ($this->users as $id => $user) {
            if (strcasecmp($user->email, $email) === 0 && $id !== $exceptId) {
                return true;
            }
        }
        return false;
    }

    public function create(User $user): int
    {
        $id = $user->id ?? $this->nextId;
        $this->nextId = max($this->nextId, $id + 1);

        $this->users[$id] = new User(
            $id,
            $user->name,
            $user->email,
            $user->passwordHash,
            $user->role,
            $user->isActive,
        );

        return $id;
    }

    public function update(int $id, User $user): void
    {
        $existing = $this->users[$id] ?? null;
        $this->users[$id] = new User(
            $id,
            $user->name,
            $user->email,
            $existing?->passwordHash ?? $user->passwordHash,
            $user->role,
            $user->isActive,
        );
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $user = $this->users[$id] ?? null;
        if ($user === null) {
            return;
        }
        $this->users[$id] = new User($id, $user->name, $user->email, $passwordHash, $user->role, $user->isActive);
    }

    public function setActive(int $id, bool $isActive): void
    {
        $user = $this->users[$id] ?? null;
        if ($user === null) {
            return;
        }
        $this->users[$id] = new User($id, $user->name, $user->email, $user->passwordHash, $user->role, $isActive);
    }

    public function all(): array
    {
        return array_values($this->users);
    }
}
