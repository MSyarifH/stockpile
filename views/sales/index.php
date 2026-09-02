<?php
/** @var callable $e @var list<\App\Entity\SalesOrder> $orders @var \App\Entity\AuthenticatedUser|null $user */
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

<?php if ($orders === []) : ?>
    <p class="empty">No sales orders yet.</p>
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
