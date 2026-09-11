<?php
/** @var callable $e @var string $csrfToken @var list<\App\Entity\BusinessPartner> $customers
 *  @var list<\App\Entity\Warehouse> $warehouses @var list<\App\Entity\Product> $products
 *  @var array<string,string> $values @var array<string,string> $errors */
?>
<h1 class="page-title">New sales order</h1>

<?php if (isset($errors['items'])) : ?>
    <p class="alert alert--error" role="alert"><?= $e($errors['items']) ?></p>
<?php endif; ?>
<?php if (isset($errors['order_date'])) : ?>
    <p class="alert alert--error" role="alert"><?= $e($errors['order_date']) ?></p>
<?php endif; ?>

<form class="card" method="post" action="/sales-orders" novalidate>
    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">

    <div class="field">
        <label for="customer_id">Customer</label>
        <select id="customer_id" name="customer_id" required>
            <option value="">Choose a customer…</option>
            <?php foreach ($customers as $customer) : ?>
                <option value="<?= (int) $customer->id ?>" <?= $values['customer_id'] === (string) $customer->id ? 'selected' : '' ?>>
                    <?= $e($customer->name) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label for="warehouse_id">Ship from warehouse</label>
        <select id="warehouse_id" name="warehouse_id" required>
            <option value="">Choose a warehouse…</option>
            <?php foreach ($warehouses as $warehouse) : ?>
                <option value="<?= (int) $warehouse->id ?>" <?= $values['warehouse_id'] === (string) $warehouse->id ? 'selected' : '' ?>>
                    <?= $e($warehouse->name) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label for="order_date">Order date</label>
        <input id="order_date" name="order_date" type="date" required max="<?= date('Y-m-d') ?>"
               value="<?= $e($values['order_date']) ?>">
    </div>

    <h2 class="card__title">Lines</h2>
    <div class="table-wrap">
        <table class="table" id="order-lines">
            <thead>
            <tr>
                <th scope="col">Product</th><th scope="col">Quantity</th>
                <th scope="col">Unit price</th><th scope="col"><span class="visually-hidden">Remove</span></th>
            </tr>
            </thead>
            <tbody>
            <tr class="line-row">
                <td data-label="Product">
                    <label class="visually-hidden">Product</label>
                    <select name="items[product_id][]">
                        <option value="">Choose a product…</option>
                        <?php foreach ($products as $product) : ?>
                            <option value="<?= (int) $product->id ?>"
                                    data-price="<?= $e((string) $product->sellingPrice) ?>"
                                    data-sku="<?= $e($product->sku) ?>">
                                <?= $e($product->sku) ?> — <?= $e($product->name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td data-label="Quantity">
                    <label class="visually-hidden">Quantity</label>
                    <input class="qty-input" type="number" min="1" value="1" name="items[quantity][]">
                </td>
                <td data-label="Unit price">
                    <label class="visually-hidden">Unit price</label>
                    <input class="qty-input" type="number" step="0.01" min="0" value="0" name="items[selling_price][]">
                </td>
                <td data-label="Remove">
                    <button class="btn btn--small btn--ghost line-remove" type="button">Remove</button>
                </td>
            </tr>
            </tbody>
        </table>
    </div>

    <div class="form-actions">
        <button class="btn" type="button" id="add-line">Add line</button>
    </div>

    <div class="form-actions">
        <button class="btn btn--primary" type="submit">Save as draft</button>
        <a class="btn btn--ghost" href="/sales-orders">Cancel</a>
    </div>
</form>

<script src="/assets/order-lines.js" defer></script>
<script src="/assets/availability.js" defer></script>
