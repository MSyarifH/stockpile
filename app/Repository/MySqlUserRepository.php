<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use PDO;

final class MySqlUserRepository implements UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByEmail(string $email): ?User
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, email, password_hash, role, is_active FROM users WHERE email = ?'
        );
        $statement->execute([$email]);
        $row = $statement->fetch();

        return is_array($row) ? User::fromRow($row) : null;
    }

    public function findById(int $id): ?User
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, email, password_hash, role, is_active FROM users WHERE id = ?'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();

        return is_array($row) ? User::fromRow($row) : null;
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM users WHERE email = ?';
        $parameters = [$email];

        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $parameters[] = $exceptId;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    public function create(User $user): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $user->name,
            $user->email,
            $user->passwordHash,
            $user->role->value,
            $user->isActive ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, User $user): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET name = ?, email = ?, role = ?, is_active = ? WHERE id = ?'
        );
        $statement->execute([
            $user->name,
            $user->email,
            $user->role->value,
            $user->isActive ? 1 : 0,
            $id,
        ]);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $statement->execute([$passwordHash, $id]);
    }

    public function setActive(int $id, bool $isActive): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?');
        $statement->execute([$isActive ? 1 : 0, $id]);
    }

    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, name, email, password_hash, role, is_active FROM users ORDER BY role, name'
        );
        $rows = $statement === false ? [] : $statement->fetchAll();

        return array_map(static fn (array $row): User => User::fromRow($row), $rows);
    }
}
