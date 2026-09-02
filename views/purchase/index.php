<?php
/** @var callable $e @var list<\App\Entity\PurchaseOrder> $orders */
?>
<div class="page-header">
    <h1 class="page-title">Purchase orders</h1>
    <a class="btn btn--primary" href="/purchase-orders/create">New purchase order</a>
</div>

<?php if ($orders === []) : ?>
    <p class="empty">No purchase orders yet. Raise one when stock runs low.</p>
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
