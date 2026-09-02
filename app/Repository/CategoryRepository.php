<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use PDO;

/**
 * Concrete class, no interface: no service branches on category data, so no
 * unit test needs to fake it. See ADR-001 for the criterion.
 */
final class CategoryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<Category> */
    public function all(): array
    {
        $statement = $this->pdo->query('SELECT id, name, description FROM categories ORDER BY name');
        $rows = $statement === false ? [] : $statement->fetchAll();

        return array_map(static fn (array $row): Category => Category::fromRow($row), $rows);
    }

    public function findById(int $id): ?Category
    {
        $statement = $this->pdo->prepare('SELECT id, name, description FROM categories WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return is_array($row) ? Category::fromRow($row) : null;
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM categories WHERE name = ?';
        $parameters = [$name];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $parameters[] = $exceptId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    public function create(Category $category): int
    {
        $statement = $this->pdo->prepare('INSERT INTO categories (name, description) VALUES (?, ?)');
        $statement->execute([$category->name, $category->description]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, Category $category): void
    {
        $statement = $this->pdo->prepare('UPDATE categories SET name = ?, description = ? WHERE id = ?');
        $statement->execute([$category->name, $category->description, $id]);
    }

    public function isUsedByProduct(int $id): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM products WHERE category_id = ? LIMIT 1');
        $statement->execute([$id]);

        return $statement->fetchColumn() !== false;
    }
}
