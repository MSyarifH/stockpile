<?php
/** @var callable $e @var string $csrfToken @var \App\Entity\Product|null $editing
 *  @var list<\App\Entity\Category> $categories @var array<string,mixed> $values @var array<string,string> $errors */
$action = $editing === null ? '/products' : '/products/' . (int) $editing->id;
?>
<h1 class="page-title"><?= $editing === null ? 'Add product' : 'Edit product' ?></h1>

<form class="card card--form" method="post" action="<?= $e($action) ?>" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">

    <div class="field">
        <label for="sku">SKU</label>
        <input id="sku" name="sku" type="text" required maxlength="64" value="<?= $e($values['sku']) ?>">
        <?php if (isset($errors['sku'])) : ?><p class="field__error"><?= $e($errors['sku']) ?></p><?php endif; ?>
        <p class="field__hint">Must be unique. Stored in upper case.</p>
    </div>

    <div class="field">
        <label for="name">Name</label>
        <input id="name" name="name" type="text" required maxlength="190" value="<?= $e($values['name']) ?>">
        <?php if (isset($errors['name'])) : ?><p class="field__error"><?= $e($errors['name']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label for="category_id">Category</label>
        <select id="category_id" name="category_id" required>
            <option value="">Choose a category…</option>
            <?php foreach ($categories as $category) : ?>
                <option value="<?= (int) $category->id ?>"
                    <?= (string) $values['category_id'] === (string) $category->id ? 'selected' : '' ?>>
                    <?= $e($category->name) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (isset($errors['category_id'])) : ?><p class="field__error"><?= $e($errors['category_id']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label for="unit">Unit</label>
        <input id="unit" name="unit" type="text" required maxlength="20" value="<?= $e($values['unit']) ?>">
    </div>

    <div class="field">
        <label for="purchase_price">Purchase price</label>
        <input id="purchase_price" name="purchase_price" type="number" step="0.01" min="0" required
               value="<?= $e($values['purchase_price']) ?>">
        <?php if (isset($errors['purchase_price'])) : ?><p class="field__error"><?= $e($errors['purchase_price']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label for="selling_price">Selling price</label>
        <input id="selling_price" name="selling_price" type="number" step="0.01" min="0" required
               value="<?= $e($values['selling_price']) ?>">
        <?php if (isset($errors['selling_price'])) : ?><p class="field__error"><?= $e($errors['selling_price']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label for="reorder_point">Reorder point</label>
        <input id="reorder_point" name="reorder_point" type="number" min="0" step="1" required
               value="<?= $e($values['reorder_point']) ?>">
        <?php if (isset($errors['reorder_point'])) : ?><p class="field__error"><?= $e($errors['reorder_point']) ?></p><?php endif; ?>
        <p class="field__hint">Flagged as low stock when total stock falls to this number or below.</p>
    </div>

    <div class="field">
        <label for="image">Product image (optional)</label>
        <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp" data-max-bytes="2097152">
        <?php if (isset($errors['image'])) : ?><p class="field__error"><?= $e($errors['image']) ?></p><?php endif; ?>
        <p class="field__hint">JPEG, PNG or WebP, up to 2 MB. Stored under a random filename.</p>
    </div>

    <div class="field field--check">
        <input id="is_active" name="is_active" type="checkbox" value="1" <?= $values['is_active'] ? 'checked' : '' ?>>
        <label for="is_active">Product is active</label>
    </div>

    <div class="form-actions">
        <button class="btn btn--primary" type="submit">Save</button>
        <a class="btn btn--ghost" href="/products">Cancel</a>
    </div>
</form>
