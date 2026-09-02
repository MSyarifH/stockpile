<?php
/** @var callable $e @var list<\App\Entity\Product> $products @var string $csrfToken @var \App\Entity\AuthenticatedUser|null $user */
?>
<div class="page-header">
    <h1 class="page-title">Products</h1>
    <?php if ($user !== null && $user->isAdmin()) : ?>
        <a class="btn btn--primary" href="/products/create">Add product</a>
    <?php endif; ?>
</div>

<?php if ($products === []) : ?>
    <p class="empty">No products yet. Add the first one to start building the catalogue.</p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="visually-hidden">Product catalogue</caption>
            <thead>
            <tr>
                <th scope="col">SKU</th>
                <th scope="col">Name</th>
                <th scope="col">Category</th>
                <th scope="col">Stock</th>
                <th scope="col">Reorder at</th>
                <th scope="col">Status</th>
                <th scope="col"><span class="visually-hidden">Actions</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($products as $product) : ?>
                <tr>
                    <td data-label="SKU"><code><?= $e($product->sku) ?></code></td>
                    <td data-label="Name"><a href="/products/<?= (int) $product->id ?>"><?= $e($product->name) ?></a></td>
                    <td data-label="Category"><?= $e($product->categoryName ?? '—') ?></td>
                    <td data-label="Stock">
                        <?= (int) $product->totalStock ?>
                        <?php if ($product->isLowStock()) : ?>
                            <span class="badge badge--off">Low</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Reorder at"><?= (int) $product->reorderPoint ?></td>
                    <td data-label="Status">
                        <span class="badge <?= $product->isActive ? 'badge--ok' : 'badge--off' ?>">
                            <?= $product->isActive ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td data-label="Actions" class="row-actions">
                        <?php if ($user !== null && $user->isAdmin()) : ?>
                            <a class="btn btn--small" href="/products/<?= (int) $product->id ?>/edit">Edit</a>
                            <form method="post" action="/products/<?= (int) $product->id ?>/active">
                                <input type="hidden" name="_token" value="<?= $e($csrfToken ?? '') ?>">
                                <input type="hidden" name="activate" value="<?= $product->isActive ? '0' : '1' ?>">
                                <button class="btn btn--small btn--ghost" type="submit">
                                    <?= $product->isActive ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                        <?php else : ?>
                            <a class="btn btn--small" href="/products/<?= (int) $product->id ?>">View</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
