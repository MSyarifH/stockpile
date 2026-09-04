<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\StockLevel;
use App\Support\Page;
use PDO;

final class MySqlProductRepository implements ProductRepository
{
    /**
     * Total stock is aggregated in SQL rather than by loading every stock row
     * into PHP: the product list needs one number per product, not the rows.
     */
    private const SELECT = '
        SELECT p.id, p.sku, p.name, p.category_id, p.unit, p.purchase_price, p.selling_price,
               p.reorder_point, p.image_path, p.is_active,
               c.name AS category_name,
               COALESCE(SUM(ps.quantity), 0) AS total_stock
          FROM products p
          JOIN categories c ON c.id = p.category_id
          LEFT JOIN product_stocks ps ON ps.product_id = p.id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(bool $activeOnly = false): array
    {
        $sql = self::SELECT;
        if ($activeOnly) {
            $sql .= ' WHERE p.is_active = 1';
        }
        $sql .= ' GROUP BY p.id ORDER BY p.name';

        $statement = $this->pdo->query($sql);
        $rows = $statement === false ? [] : $statement->fetchAll();

        return array_map(static fn (array $row): Product => Product::fromRow($row), $rows);
    }

    public function paginate(ProductFilter $filter, int $page): Page
    {
        [$where, $having, $parameters] = $this->conditionsFor($filter);

        // Counted with the same conditions as the page itself, so the pager can
        // never claim a page that the list will not fill.
        // reorder_point must be in the inner SELECT: MySQL cannot resolve a
        // column in HAVING that the grouped query does not project.
        $countSql = '
            SELECT COUNT(*) FROM (
                SELECT p.id, p.reorder_point
                  FROM products p
                  LEFT JOIN product_stocks ps ON ps.product_id = p.id
                  ' . $where . '
                 GROUP BY p.id, p.reorder_point
                  ' . $having . '
            ) counted';
        $countStatement = $this->pdo->prepare($countSql);
        $countStatement->execute($parameters);
        $total = (int) $countStatement->fetchColumn();

        $sql = self::SELECT . ' ' . $where . ' GROUP BY p.id ' . $having
            . ' ORDER BY p.name LIMIT ? OFFSET ?';
        $statement = $this->pdo->prepare($sql);

        $position = 1;
        foreach ($parameters as $value) {
            $statement->bindValue($position++, $value);
        }
        // LIMIT/OFFSET must be bound as integers: with emulated prepares off,
        // MySQL rejects them as quoted strings.
        $statement->bindValue($position++, Page::PER_PAGE, PDO::PARAM_INT);
        $statement->bindValue($position, Page::offsetFor($page), PDO::PARAM_INT);
        $statement->execute();

        $items = array_map(
            static fn (array $row): Product => Product::fromRow($row),
            $statement->fetchAll(),
        );

        return new Page($items, $total, Page::normalisePage($page));
    }

    /**
     * @return array{0:string,1:string,2:list<mixed>} WHERE clause, HAVING clause, bound values
     */
    private function conditionsFor(ProductFilter $filter): array
    {
        $conditions = [];
        $parameters = [];

        if ($filter->activeOnly) {
            $conditions[] = 'p.is_active = 1';
        }

        if ($filter->search !== '') {
            // Wildcards are added around the BOUND value, never concatenated
            // into the SQL text.
            $conditions[] = '(p.name LIKE ? OR p.sku LIKE ?)';
            $parameters[] = '%' . $filter->search . '%';
            $parameters[] = '%' . $filter->search . '%';
        }

        if ($filter->categoryId !== null) {
            $conditions[] = 'p.category_id = ?';
            $parameters[] = $filter->categoryId;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        // Stock status compares against an aggregate, so it belongs in HAVING:
        // the total does not exist yet when WHERE is evaluated.
        $having = match ($filter->stockStatus) {
            ProductFilter::STOCK_LOW => 'HAVING COALESCE(SUM(ps.quantity), 0) <= p.reorder_point',
            ProductFilter::STOCK_NORMAL => 'HAVING COALESCE(SUM(ps.quantity), 0) > p.reorder_point',
            default => '',
        };

        return [$where, $having, $parameters];
    }

    public function findById(int $id): ?Product
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE p.id = ? GROUP BY p.id');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return is_array($row) ? Product::fromRow($row) : null;
    }

    public function findBySku(string $sku): ?Product
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE p.sku = ? GROUP BY p.id');
        $statement->execute([$sku]);
        $row = $statement->fetch();

        return is_array($row) ? Product::fromRow($row) : null;
    }

    public function skuExists(string $sku, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM products WHERE sku = ?';
        $parameters = [$sku];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $parameters[] = $exceptId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    public function create(Product $product): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO products
                 (sku, name, category_id, unit, purchase_price, selling_price, reorder_point, image_path, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $product->sku,
            $product->name,
            $product->categoryId,
            $product->unit,
            $product->purchasePrice,
            $product->sellingPrice,
            $product->reorderPoint,
            $product->imagePath,
            $product->isActive ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, Product $product): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE products
                SET sku = ?, name = ?, category_id = ?, unit = ?, purchase_price = ?,
                    selling_price = ?, reorder_point = ?, is_active = ?
              WHERE id = ?'
        );
        $statement->execute([
            $product->sku,
            $product->name,
            $product->categoryId,
            $product->unit,
            $product->purchasePrice,
            $product->sellingPrice,
            $product->reorderPoint,
            $product->isActive ? 1 : 0,
            $id,
        ]);
    }

    public function setActive(int $id, bool $isActive): void
    {
        $statement = $this->pdo->prepare('UPDATE products SET is_active = ? WHERE id = ?');
        $statement->execute([$isActive ? 1 : 0, $id]);
    }

    public function updateImagePath(int $id, ?string $imagePath): void
    {
        $statement = $this->pdo->prepare('UPDATE products SET image_path = ? WHERE id = ?');
        $statement->execute([$imagePath, $id]);
    }

    public function isUsedOnAnyOrder(int $id): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM purchase_order_items WHERE product_id = ?
              UNION ALL
             SELECT 1 FROM sales_order_items WHERE product_id = ?
              LIMIT 1'
        );
        $statement->execute([$id, $id]);

        return $statement->fetchColumn() !== false;
    }

    public function stockLevels(int $productId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ps.product_id, ps.warehouse_id, w.name AS warehouse_name, ps.quantity
               FROM product_stocks ps
               JOIN warehouses w ON w.id = ps.warehouse_id
              WHERE ps.product_id = ?
              ORDER BY w.name'
        );
        $statement->execute([$productId]);

        return array_map(
            static fn (array $row): StockLevel => StockLevel::fromRow($row),
            $statement->fetchAll(),
        );
    }

    public function createStockRowsForAllWarehouses(int $productId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO product_stocks (product_id, warehouse_id, quantity)
             SELECT ?, w.id, 0 FROM warehouses w
             WHERE NOT EXISTS (
                 SELECT 1 FROM product_stocks ps WHERE ps.product_id = ? AND ps.warehouse_id = w.id
             )'
        );
        $statement->execute([$productId, $productId]);
    }

    public function lowStock(): array
    {
        // HAVING, not WHERE: the comparison is against an aggregate, which does
        // not exist yet at WHERE time.
        $statement = $this->pdo->query(
            self::SELECT . ' WHERE p.is_active = 1
                             GROUP BY p.id
                             HAVING total_stock <= p.reorder_point
                             ORDER BY (p.reorder_point - total_stock) DESC, p.name'
        );
        $rows = $statement === false ? [] : $statement->fetchAll();

        return array_map(static fn (array $row): Product => Product::fromRow($row), $rows);
    }

    public function forSelect(): array
    {
        // No LEFT JOIN product_stocks, no GROUP BY, no SUM — just the product
        // columns the dropdown needs.  total_stock is hardcoded to 0 because
        // Product::fromRow expects the column but the form never displays it.
        $statement = $this->pdo->query(
            'SELECT p.id, p.sku, p.name, p.category_id, p.unit,
                    p.purchase_price, p.selling_price,
                    p.reorder_point, p.image_path, p.is_active,
                    c.name AS category_name,
                    0 AS total_stock
               FROM products p
               JOIN categories c ON c.id = p.category_id
              WHERE p.is_active = 1
              ORDER BY p.name'
        );
        $rows = $statement === false ? [] : $statement->fetchAll();

        return array_map(static fn (array $row): Product => Product::fromRow($row), $rows);
    }
}
