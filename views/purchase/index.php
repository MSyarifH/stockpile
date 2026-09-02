<?php
/** @var callable $e @var \App\Support\Page<\App\Entity\PurchaseOrder> $page
 *  @var \App\Repository\OrderFilter $filter @var list<\App\Entity\PurchaseOrderStatus> $statuses
 *  @var \App\Support\QueryString $query @var \App\Support\View $view */
$orders = $page->items;
?>
<div class="page-header">
    <h1 class="page-title">Purchase orders</h1>
    <a class="btn btn--primary" href="/purchase-orders/create">New purchase order</a>
</div>

<form class="filters" method="get" action="/purchase-orders">
    <div class="field">
        <label for="q">Search number or supplier</label>
        <input id="q" name="q" type="search" value="<?= $e($filter->search) ?>" placeholder="e.g. PO-2026 or Sinar">
    </div>
    <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $status) : ?>
                <option value="<?= $e($status->value) ?>" <?= $filter->status === $status->value ? 'selected' : '' ?>>
                    <?= $e($status->label()) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="sort">Order date</label>
        <select id="sort" name="sort">
            <option value="desc" <?= $filter->sortDirection === 'desc' ? 'selected' : '' ?>>Newest first</option>
            <option value="asc" <?= $filter->sortDirection === 'asc' ? 'selected' : '' ?>>Oldest first</option>
        </select>
    </div>
    <div class="filters__actions">
        <button class="btn btn--primary" type="submit">Apply</button>
        <?php if ($filter->isActive()) : ?>
            <a class="btn btn--ghost" href="/purchase-orders">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($orders === []) : ?>
    <p class="empty">
        <?= $filter->isActive()
            ? 'No purchase orders match those filters.'
            : 'No purchase orders yet. Raise one when stock runs low.' ?>
    </p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="visually-hidden">Purchase orders</caption>
            <thead>
            <tr>
                <th scope="col">Number</th><th scope="col">Supplier</th>
                <th scope="col">Destination</th><th scope="col">Date</th>
                <th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $order) : ?>
                <tr>
                    <td data-label="Number">
                        <a href="/purchase-orders/<?= (int) $order->id ?>"><code><?= $e($order->poNumber) ?></code></a>
                    </td>
                    <td data-label="Supplier"><?= $e($order->supplierName ?? '') ?></td>
                    <td data-label="Destination"><?= $e($order->warehouseName ?? '') ?></td>
                    <td data-label="Date"><?= $e($order->orderDate) ?></td>
                    <td data-label="Status">
                        <span class="badge <?= $e($order->status->badgeModifier()) ?>">
                            <?= $e($order->status->label()) ?></span>
                    </td>
                    <td data-label="Actions">
                        <a class="btn btn--small" href="/purchase-orders/<?= (int) $order->id ?>">Open</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?= $view->render('partial.pagination', ['page' => $page, 'query' => $query]) ?>
