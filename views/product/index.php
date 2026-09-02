<?php
/** @var callable $e @var \App\Support\Page<\App\Entity\Product> $page
 *  @var list<\App\Entity\Category> $categories @var \App\Repository\ProductFilter $filter
 *  @var \App\Support\QueryString $query @var string $csrfToken
 *  @var \App\Entity\AuthenticatedUser|null $user @var \App\Support\View $view */
$products = $page->items;
?>
<div class="page-header">
    <h1 class="page-title">Products</h1>
    <?php if ($user !== null && $user->isAdmin()) : ?>
        <a class="btn btn--primary" href="/products/create">Add product</a>
    <?php endif; ?>
</div>

<form class="filters" method="get" action="/products">
    <div class="field">
        <label for="q">Search name or SKU</label>
        <input id="q" name="q" type="search" value="<?= $e($filter->search) ?>" placeholder="e.g. keyboard or SKU-ELK">
    </div>
    <div class="field">
        <label for="category">Category</label>
        <select id="category" name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $category) : ?>
                <option value="<?= (int) $category->id ?>" <?= $filter->categoryId === $category->id ? 'selected' : '' ?>>
                    <?= $e($category->name) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="stock">Stock status</label>
        <select id="stock" name="stock">
            <option value="">Any</option>
            <option value="low" <?= $filter->stockStatus === 'low' ? 'selected' : '' ?>>Low stock</option>
            <option value="normal" <?= $filter->stockStatus === 'normal' ? 'selected' : '' ?>>Normal</option>
        </select>
    </div>
    <div class="filters__actions">
        <button class="btn btn--primary" type="submit">Apply</button>
        <?php if ($filter->isActive()) : ?>
            <a class="btn btn--ghost" href="/products">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($products === []) : ?>
    <p class="empty">
        <?= $filter->isActive()
            ? 'No products match those filters. Try widening the search.'
            : 'No products yet. Add the first one to start building the catalogue.' ?>
    </p>
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

<?= $view->render('partial.pagination', ['page' => $page, 'query' => $query]) ?>
