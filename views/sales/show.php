<?php
/** @var callable $e @var \App\Entity\SalesOrder $order @var string $csrfToken
 *  @var \App\Entity\AuthenticatedUser|null $user */
use App\Entity\Role;
use App\Entity\SalesOrderStatus;

$status = $order->status;
$isOwner = $user !== null && $order->isOwnedBy($user);
$isAdmin = $user !== null && $user->isAdmin();
$canApprove = $user !== null && $order->canBeApprovedBy($user) && $status === SalesOrderStatus::PendingApproval;
$base = '/sales-orders/' . (int) $order->id;
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><code><?= $e($order->soNumber) ?></code></h1>
        <p class="muted">
            <?= $e($order->customerName ?? '') ?> · from <?= $e($order->warehouseName ?? '') ?>
            · <?= $e($order->orderDate) ?>
            · raised by <?= $e($order->createdByName ?? '') ?>
            <?php if ($order->approvedByName !== null) : ?>
                · approved by <?= $e($order->approvedByName) ?>
            <?php endif; ?>
        </p>
    </div>
    <span class="badge <?= $e($status->badgeModifier()) ?>"><?= $e($status->label()) ?></span>
</div>

<?php if ($status === SalesOrderStatus::PendingApproval && $isAdmin && $order->isOwnedBy($user)) : ?>
    <p class="alert alert--error" role="status">
        You raised this order, so you cannot approve it. Another Admin must review it —
        segregation of duties means one person may never both raise and approve the same order.
    </p>
<?php endif; ?>

<?php if ($order->hasLinesShortOnStock() && !$status->isFinal()) : ?>
    <p class="alert alert--warn" role="status">
        Some lines exceed the stock currently in <?= $e($order->warehouseName ?? 'the source warehouse') ?>.
        Stock is not reserved at approval, so goods issue will be refused unless it is replenished.
    </p>
<?php endif; ?>

<div class="actions-bar">
    <?php if ($status === SalesOrderStatus::Draft && ($isOwner || $isAdmin)) : ?>
        <form method="post" action="<?= $e($base) ?>/submit">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <button class="btn btn--primary" type="submit">Submit for approval</button>
        </form>
    <?php endif; ?>

    <?php if ($canApprove) : ?>
        <form method="post" action="<?= $e($base) ?>/approve">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <button class="btn btn--primary" type="submit">Approve</button>
        </form>
        <form method="post" action="<?= $e($base) ?>/reject">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <button class="btn" type="submit">Send back to draft</button>
        </form>
    <?php endif; ?>

    <?php if ($status->acceptsGoodsIssue() && $user !== null && $user->is(Role::Admin, Role::WarehouseStaff)) : ?>
        <form method="post" action="<?= $e($base) ?>/issue">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <button class="btn btn--primary" type="submit">Issue goods</button>
        </form>
    <?php endif; ?>

    <?php if (!$status->isFinal() && ($isAdmin || ($isOwner && $status === SalesOrderStatus::Draft))) : ?>
        <form method="post" action="<?= $e($base) ?>/cancel">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <button class="btn btn--ghost" type="submit">Cancel order</button>
        </form>
    <?php endif; ?>
</div>

<div class="table-wrap">
    <table class="table">
        <caption class="visually-hidden">Order lines</caption>
        <thead>
        <tr>
            <th scope="col">SKU</th><th scope="col">Product</th>
            <th scope="col">Quantity</th><th scope="col">In warehouse</th>
            <th scope="col">Unit price</th><th scope="col">Line total</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($order->items as $item) : ?>
            <tr>
                <td data-label="SKU"><code><?= $e($item->productSku ?? '') ?></code></td>
                <td data-label="Product"><?= $e($item->productName ?? '') ?></td>
                <td data-label="Quantity"><?= (int) $item->quantity ?></td>
                <td data-label="In warehouse">
                    <?= (int) ($item->availableInWarehouse ?? 0) ?>
                    <?php if ($item->isShortOnStock()) : ?>
                        <span class="badge badge--off">Short</span>
                    <?php endif; ?>
                </td>
                <td data-label="Unit price">Rp <?= number_format($item->sellingPrice, 2, ',', '.') ?></td>
                <td data-label="Line total">Rp <?= number_format($item->lineTotal(), 2, ',', '.') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr>
            <td colspan="5"><strong>Order total</strong></td>
            <td><strong>Rp <?= number_format($order->total(), 2, ',', '.') ?></strong></td>
        </tr>
        </tfoot>
    </table>
</div>
