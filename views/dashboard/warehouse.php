<?php
/** @var callable $e @var \App\Entity\AuthenticatedUser $user
 *  @var int $receipt_queue @var int $issue_queue @var int $low_stock_count
 *  @var list<\App\Entity\Product> $low_stock @var int $total_units */
?>
<h1 class="page-title">Warehouse dashboard</h1>
<p class="muted">Work waiting for the warehouse, and what needs reordering.</p>

<div class="tiles">
    <div class="tile <?= $receipt_queue > 0 ? 'tile--warn' : '' ?>">
        <p class="tile__label">Goods receipt queue</p>
        <p class="tile__value"><?= (int) $receipt_queue ?></p>
        <p class="tile__note"><a href="/purchase-orders?status=Ordered">Placed purchase orders</a></p>
    </div>
    <div class="tile <?= $issue_queue > 0 ? 'tile--warn' : '' ?>">
        <p class="tile__label">Goods issue queue</p>
        <p class="tile__value"><?= (int) $issue_queue ?></p>
        <p class="tile__note"><a href="/sales-orders?status=Approved">Approved sales orders</a></p>
    </div>
    <div class="tile">
        <p class="tile__label">Units in stock</p>
        <p class="tile__value"><?= number_format($total_units, 0, ',', '.') ?></p>
        <p class="tile__note">All warehouses</p>
    </div>
    <div class="tile <?= $low_stock_count > 0 ? 'tile--warn' : '' ?>">
        <p class="tile__label">Below reorder point</p>
        <p class="tile__value"><?= (int) $low_stock_count ?></p>
        <p class="tile__note"><a href="/products?stock=low">See the list</a></p>
    </div>
</div>

<section class="card">
    <h2 class="card__title">Low stock</h2>
    <?php if ($low_stock === []) : ?>
        <p class="muted">Nothing is at or below its reorder point.</p>
    <?php else : ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th scope="col">SKU</th><th scope="col">Product</th>
                    <th scope="col">In stock</th><th scope="col">Reorder at</th><th scope="col">Short by</th>
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
                        <td data-label="Short by"><?= max(0, $product->reorderPoint - $product->totalStock) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
