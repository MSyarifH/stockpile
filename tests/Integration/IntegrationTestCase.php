<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that need real MySQL.
 *
 * Each test creates its own fixture rows and removes them afterwards, so tests
 * are independent and order-insensitive (the I and R of FIRST). Nothing here
 * relies on the seed data, which could change.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected PDO $pdo;

    /** @var list<int> product ids created by the test, removed in tearDown */
    private array $createdProducts = [];

    protected function setUp(): void
    {
        $this->pdo = $this->connect();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdProducts as $productId) {
            $this->pdo->prepare('DELETE FROM stock_ledger WHERE product_id = ?')->execute([$productId]);
            $this->pdo->prepare('DELETE FROM product_stocks WHERE product_id = ?')->execute([$productId]);
            $this->pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
        }
        $this->createdProducts = [];
    }

    /** A fresh connection. Concurrency tests need more than one. */
    protected function connect(): PDO
    {
        return Database::connect([
            'host' => (string) (getenv('DB_HOST') ?: 'db'),
            'port' => (string) (getenv('DB_PORT') ?: '3306'),
            'name' => (string) getenv('DB_NAME'),
            'user' => (string) getenv('DB_USER'),
            'password' => (string) getenv('DB_PASSWORD'),
        ]);
    }

    /**
     * Creates a throwaway product with a known balance in warehouse 1.
     *
     * @return array{0:int,1:int} product id and warehouse id
     */
    protected function givenProductWithStock(int $quantity, string $suffix = ''): array
    {
        $warehouseId = (int) $this->pdo->query('SELECT id FROM warehouses ORDER BY id LIMIT 1')->fetchColumn();
        $categoryId = (int) $this->pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();

        $sku = 'TEST-' . strtoupper(bin2hex(random_bytes(4))) . $suffix;
        $statement = $this->pdo->prepare(
            'INSERT INTO products (sku, name, category_id, unit, purchase_price, selling_price, reorder_point, is_active)
             VALUES (?, ?, ?, "pcs", 100.00, 150.00, 0, 1)'
        );
        $statement->execute([$sku, 'Integration Fixture ' . $sku, $categoryId]);
        $productId = (int) $this->pdo->lastInsertId();
        $this->createdProducts[] = $productId;

        $this->pdo->prepare('INSERT INTO product_stocks (product_id, warehouse_id, quantity) VALUES (?, ?, ?)')
            ->execute([$productId, $warehouseId, $quantity]);

        return [$productId, $warehouseId];
    }

    protected function balance(int $productId, int $warehouseId, ?PDO $pdo = null): int
    {
        $statement = ($pdo ?? $this->pdo)->prepare(
            'SELECT quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?'
        );
        $statement->execute([$productId, $warehouseId]);

        return (int) $statement->fetchColumn();
    }

    protected function ledgerSum(int $productId, int $warehouseId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(quantity), 0) FROM stock_ledger WHERE product_id = ? AND warehouse_id = ?'
        );
        $statement->execute([$productId, $warehouseId]);

        return (int) $statement->fetchColumn();
    }

    protected function anyUserId(): int
    {
        return (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    }
}
