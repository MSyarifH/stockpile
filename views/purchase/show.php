<?php
/** @var callable $e @var \App\Entity\PurchaseOrder $order @var string $csrfToken
 *  @var \App\Entity\AuthenticatedUser|null $user */
$status = $order->status;
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><code><?= $e($order->poNumber) ?></code></h1>
        <p class="muted">
            <?= $e($order->supplierName ?? '') ?> → <?= $e($order->warehouseName ?? '') ?>
            · <?= $e($order->orderDate) ?>
            · raised by <?= $e($order->createdByName ?? '') ?>
        </p>
    </div>
    <span class="badge <?= $e($status->badgeModifier()) ?>"><?= $e($status->label()) ?></span>
</div>

<div class="actions-bar">
    <?php if ($status === \App\Entity\PurchaseOrderStatus::Draft && $user !== null && $user->isAdmin()) : ?>
        <form method="post" action="/purchase-orders/<?= (int) $order->id ?>/place">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <button class="btn btn--primary" type="submit">Place order with supplier</button>
        </form>
    <?php elseif ($status === \App\Entity\PurchaseOrderStatus::Draft) : ?>
        <p class="muted">Waiting for an Admin to place this order with the supplier.</p>
    <?php endif; ?>

    <?php if (!$status->isFinal()) : ?>
        <form method="post" action="/purchase-orders/<?= (int) $order->id ?>/cancel">
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <button class="btn btn--ghost" type="submit">Cancel order</button>
        </form>
    <?php endif; ?>
</div>

<form method="post" action="/purchase-orders/<?= (int) $order->id ?>/receive">
    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">

    <div class="table-wrap">
        <table class="table">
            <caption class="visually-hidden">Order lines</caption>
            <thead>
            <tr>
                <th scope="col">SKU</th><th scope="col">Product</th>
                <th scope="col">Ordered</th><th scope="col">Received</th>
                <th scope="col">Outstanding</th><th scope="col">Unit price</th>
                <?php if ($status->acceptsGoodsReceipt()) : ?>
                    <th scope="col">Receive now</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($order->items as $item) : ?>
                <tr>
                    <td data-label="SKU"><code><?= $e($item->productSku ?? '') ?></code></td>
                    <td data-label="Product"><?= $e($item->productName ?? '') ?></td>
                    <td data-label="Ordered"><?= (int) $item->quantity ?></td>
                    <td data-label="Received"><?= (int) $item->receivedQuantity ?></td>
                    <td data-label="Outstanding">
                        <?php if ($item->isFullyReceived()) : ?>
                            <span class="badge badge--ok">Complete</span>
                        <?php else : ?>
                            <?= (int) $item->outstanding() ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="Unit price">Rp <?= number_format($item->purchasePrice, 2, ',', '.') ?></td>
                    <?php if ($status->acceptsGoodsReceipt()) : ?>
                        <td data-label="Receive now">
                            <label class="visually-hidden" for="recv-<?= (int) $item->id ?>">
                                Quantity received for <?= $e($item->productName ?? '') ?>
                            </label>
                            <input id="recv-<?= (int) $item->id ?>" class="qty-input" type="number" min="0"
                                   max="<?= (int) $item->outstanding() ?>" value="0"
                                   name="received[<?= (int) $item->id ?>]"
                                   <?= $item->isFullyReceived() ? 'disabled' : '' ?>>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <td colspan="5"><strong>Order total</strong></td>
                <td colspan="2"><strong>Rp <?= number_format($order->total(), 2, ',', '.') ?></strong></td>
            </tr>
            </tfoot>
        </table>
    </div>

    <?php if ($status->acceptsGoodsReceipt()) : ?>
        <div class="form-actions">
            <button class="btn btn--primary" type="submit">Record goods receipt</button>
            <p class="field__hint">
                Partial receipt is allowed — enter only what arrived. Stock and the ledger are
                updated in one transaction.
            </p>
        </div>
    <?php endif; ?>
</form>
