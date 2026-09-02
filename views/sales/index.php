<?php
/** @var callable $e @var \App\Support\Page<\App\Entity\SalesOrder> $page
 *  @var \App\Repository\OrderFilter $filter @var list<\App\Entity\SalesOrderStatus> $statuses
 *  @var \App\Support\QueryString $query @var \App\Entity\AuthenticatedUser|null $user
 *  @var \App\Support\View $view */
$orders = $page->items;
$canCreate = $user !== null && !$user->is(\App\Entity\Role::WarehouseStaff);
?>
<div class="page-header">
    <h1 class="page-title">Sales orders</h1>
    <?php if ($canCreate) : ?>
        <a class="btn btn--primary" href="/sales-orders/create">New sales order</a>
    <?php endif; ?>
</div>

<?php if ($user !== null && $user->is(\App\Entity\Role::Sales)) : ?>
    <p class="muted">Showing only the orders you raised.</p>
<?php endif; ?>

<form class="filters" method="get" action="/sales-orders">
    <div class="field">
        <label for="q">Search number or customer</label>
        <input id="q" name="q" type="search" value="<?= $e($filter->search) ?>" placeholder="e.g. SO-2026 or Maju">
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
            <a class="btn btn--ghost" href="/sales-orders">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($orders === []) : ?>
    <p class="empty">
        <?= $filter->isActive() ? 'No sales orders match those filters.' : 'No sales orders yet.' ?>
    </p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="visually-hidden">Sales orders</caption>
            <thead>
            <tr>
                <th scope="col">Number</th><th scope="col">Customer</th>
                <th scope="col">From</th><th scope="col">Date</th>
                <th scope="col">Raised by</th><th scope="col">Status</th>
                <th scope="col"><span class="visually-hidden">Actions</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $order) : ?>
                <tr>
                    <td data-label="Number">
                        <a href="/sales-orders/<?= (int) $order->id ?>"><code><?= $e($order->soNumber) ?></code></a>
                    </td>
                    <td data-label="Customer"><?= $e($order->customerName ?? '') ?></td>
                    <td data-label="From"><?= $e($order->warehouseName ?? '') ?></td>
                    <td data-label="Date"><?= $e($order->orderDate) ?></td>
                    <td data-label="Raised by"><?= $e($order->createdByName ?? '') ?></td>
                    <td data-label="Status">
                        <span class="badge <?= $e($order->status->badgeModifier()) ?>">
                            <?= $e($order->status->label()) ?></span>
                    </td>
                    <td data-label="Actions">
                        <a class="btn btn--small" href="/sales-orders/<?= (int) $order->id ?>">Open</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?= $view->render('partial.pagination', ['page' => $page, 'query' => $query]) ?>
