<?php
/** @var callable $e @var \App\Entity\AuthenticatedUser $user
 *  @var float $inventory_value @var int $total_units @var int $low_stock_count @var int $active_products
 *  @var array<string,int> $purchase_orders @var array<string,int> $sales_orders @var int $pending_approval
 *  @var list<\App\Entity\Product> $low_stock
 *  @var list<string> $salesStatuses @var list<string> $purchaseStatuses */
?>
<h1 class="page-title">Admin dashboard</h1>
<p class="muted">All figures are computed from aggregation queries over the transactional tables.</p>

<div class="tiles">
    <div class="tile">
        <p class="tile__label">Inventory value (at cost)</p>
        <p class="tile__value">Rp <?= number_format($inventory_value, 0, ',', '.') ?></p>
        <p class="tile__note">Valued at purchase price, not selling price</p>
    </div>
    <div class="tile">
        <p class="tile__label">Units in stock</p>
        <p class="tile__value"><?= number_format($total_units, 0, ',', '.') ?></p>
        <p class="tile__note">Across all warehouses</p>
    </div>
    <div class="tile <?= $low_stock_count > 0 ? 'tile--warn' : '' ?>">
        <p class="tile__label">Below reorder point</p>
        <p class="tile__value"><?= (int) $low_stock_count ?></p>
        <p class="tile__note">of <?= (int) $active_products ?> active products</p>
    </div>
    <div class="tile <?= $pending_approval > 0 ? 'tile--warn' : '' ?>">
        <p class="tile__label">Awaiting your approval</p>
        <p class="tile__value"><?= (int) $pending_approval ?></p>
        <p class="tile__note"><a href="/sales-orders?status=PendingApproval">Review orders</a></p>
    </div>
</div>

<div class="grid">
    <section class="card">
        <h2 class="card__title">Purchase orders by status</h2>
        <table class="table">
            <thead><tr><th scope="col">Status</th><th scope="col">Count</th></tr></thead>
            <tbody>
            <?php foreach ($purchaseStatuses as $status) : ?>
                <tr>
                    <td data-label="Status">
                        <a href="/purchase-orders?status=<?= $e($status) ?>"><?= $e($status) ?></a>
                    </td>
                    <td data-label="Count"><?= (int) ($purchase_orders[$status] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2 class="card__title">Sales orders by status</h2>
        <table class="table">
            <thead><tr><th scope="col">Status</th><th scope="col">Count</th></tr></thead>
            <tbody>
            <?php foreach ($salesStatuses as $status) : ?>
                <tr>
                    <td data-label="Status">
                        <a href="/sales-orders?status=<?= $e($status) ?>"><?= $e($status) ?></a>
                    </td>
                    <td data-label="Count"><?= (int) ($sales_orders[$status] ?? 0) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>

<section class="card">
    <h2 class="card__title">Needs reordering</h2>
    <?php if ($low_stock === []) : ?>
        <p class="muted">Nothing is at or below its reorder point.</p>
    <?php else : ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th scope="col">SKU</th><th scope="col">Product</th>
                    <th scope="col">In stock</th><th scope="col">Reorder at</th>
                </tr></thead>
                <tbody>
                <?php foreach ($low_stock as $product) : ?>
                    <tr>
                        <td data-label="SKU"><code><?= $e($product->sku) ?></code></td>
                        <td data-label="Product">
                            <a href="/products/<?= (int) $product->id ?>"><?= $e($product->name) ?></a>
                        </td>
                        <td data-label="In stock"><?= (int) $product->totalStock ?></td>
                        <td data-label="Reorder at"><?= (int) $product->reorderPoint ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="field__hint"><a href="/products?stock=low">See the full low-stock list</a></p>
    <?php endif; ?>
</section>
