<?php
/** @var callable $e @var \App\Entity\Product $product @var list<\App\Entity\StockLevel> $levels @var \App\Entity\AuthenticatedUser|null $user */
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= $e($product->name) ?></h1>
        <p class="muted"><code><?= $e($product->sku) ?></code> · <?= $e($product->categoryName ?? '—') ?></p>
    </div>
    <?php if ($user !== null && $user->isAdmin()) : ?>
        <a class="btn btn--primary" href="/products/<?= (int) $product->id ?>/edit"><?= $icon('pencil') ?>Edit</a>
    <?php endif; ?>
</div>

<div class="grid">
    <section class="card">
        <h2 class="card__title">Details</h2>
        <dl class="detail">
            <dt>Unit</dt><dd><?= $e($product->unit) ?></dd>
            <dt>Purchase price</dt><dd>Rp <?= number_format($product->purchasePrice, 2, ',', '.') ?></dd>
            <dt>Selling price</dt><dd>Rp <?= number_format($product->sellingPrice, 2, ',', '.') ?></dd>
            <dt>Reorder point</dt><dd><?= (int) $product->reorderPoint ?></dd>
            <dt>Status</dt>
            <dd><span class="badge <?= $product->isActive ? 'badge--ok' : 'badge--off' ?>">
                <?= $product->isActive ? 'Active' : 'Inactive' ?></span></dd>
        </dl>
        <?php if ($product->imagePath !== null) : ?>
            <img class="product-image" src="<?= $e($product->imagePath) ?>" alt="<?= $e($product->name) ?>">
        <?php endif; ?>
    </section>

    <section class="card">
        <h2 class="card__title">Stock by warehouse</h2>
        <p class="muted">
            Total <strong><?= (int) $product->totalStock ?></strong> <?= $e($product->unit) ?>
            <?php if ($product->isLowStock()) : ?>
                <span class="badge badge--off">At or below reorder point</span>
            <?php endif; ?>
        </p>
        <?php if ($levels === []) : ?>
            <p class="empty">No stock rows yet.</p>
        <?php else : ?>
            <table class="table">
                <thead><tr><th scope="col">Warehouse</th><th scope="col">Quantity</th></tr></thead>
                <tbody>
                <?php foreach ($levels as $level) : ?>
                    <tr>
                        <td data-label="Warehouse"><?= $e($level->warehouseName) ?></td>
                        <td data-label="Quantity"><?= (int) $level->quantity ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <p class="field__hint">Quantities change only through goods receipt and goods issue.</p>
    </section>
</div>
