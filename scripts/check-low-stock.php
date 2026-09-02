<?php

/**
 * JOB-01. Reports products at or below their reorder point.
 *
 *   docker compose exec app php scripts/check-low-stock.php
 *   docker compose exec app php scripts/check-low-stock.php --csv
 *
 * Deliberately outside the web request cycle: it has no session, no HTTP, and
 * its own composition root. In production this is what cron would call, which
 * is exactly why it must not depend on anything a browser provides.
 *
 * It reuses the SAME repository and the SAME low-stock definition as the
 * dashboard and the product list. A separate query here would eventually
 * disagree with the screen, and then nobody would know which was right.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Entity\Product;
use App\Repository\MySqlProductRepository;
use App\Support\Database;

/** @var array{db:array{host:string,port:string,name:string,user:string,password:string}} $config */
$config = require dirname(__DIR__) . '/config/config.php';

$asCsv = in_array('--csv', $argv, true);

try {
    $pdo = Database::connect($config['db']);
} catch (Throwable $e) {
    // Exit codes matter here: cron and CI decide success from them, and a
    // scheduled job that fails silently is worse than no job at all.
    fwrite(STDERR, 'Could not connect to the database: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

$products = (new MySqlProductRepository($pdo))->lowStock();

if ($asCsv) {
    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fputcsv($out, ['SKU', 'Product', 'In stock', 'Reorder point', 'Shortfall']);
        foreach ($products as $product) {
            fputcsv($out, [
                $product->sku,
                $product->name,
                $product->totalStock,
                $product->reorderPoint,
                max(0, $product->reorderPoint - $product->totalStock),
            ]);
        }
        fclose($out);
    }
    exit($products === [] ? 0 : 1);
}

$generatedAt = date('Y-m-d H:i:s');
echo "Low stock report — {$generatedAt}", PHP_EOL;
echo str_repeat('=', 72), PHP_EOL;

if ($products === []) {
    echo 'No products are at or below their reorder point.', PHP_EOL;
    exit(0);
}

printf("%-22s %-30s %8s %8s%s", 'SKU', 'PRODUCT', 'STOCK', 'REORDER', PHP_EOL);
echo str_repeat('-', 72), PHP_EOL;

$totalShortfall = 0;
foreach ($products as $product) {
    $shortfall = max(0, $product->reorderPoint - $product->totalStock);
    $totalShortfall += $shortfall;

    printf(
        "%-22s %-30s %8d %8d%s",
        $product->sku,
        mb_strimwidth($product->name, 0, 30, '…'),
        $product->totalStock,
        $product->reorderPoint,
        PHP_EOL,
    );
}

echo str_repeat('-', 72), PHP_EOL;
printf(
    '%d product(s) need reordering; %d unit(s) short in total.%s',
    count($products),
    $totalShortfall,
    PHP_EOL,
);

// Non-zero exit signals "action needed", so a cron wrapper can alert on it
// without parsing this text.
exit(1);
