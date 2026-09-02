<?php
/** @var callable $e @var string $csrfToken @var \App\Entity\Warehouse|null $editing
 *  @var array<string,mixed> $values @var array<string,string> $errors */
$action = $editing === null ? '/warehouses' : '/warehouses/' . (int) $editing->id;
?>
<h1 class="page-title"><?= $editing === null ? 'Add warehouse' : 'Edit warehouse' ?></h1>

<form class="card card--form" method="post" action="<?= $e($action) ?>" novalidate>
    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
    <div class="field">
        <label for="name">Name</label>
        <input id="name" name="name" type="text" required maxlength="120" value="<?= $e($values['name']) ?>">
        <?php if (isset($errors['name'])) : ?><p class="field__error"><?= $e($errors['name']) ?></p><?php endif; ?>
    </div>
    <div class="field">
        <label for="location">Location</label>
        <input id="location" name="location" type="text" required maxlength="190" value="<?= $e($values['location']) ?>">
        <?php if (isset($errors['location'])) : ?><p class="field__error"><?= $e($errors['location']) ?></p><?php endif; ?>
    </div>
    <div class="field field--check">
        <input id="is_active" name="is_active" type="checkbox" value="1" <?= $values['is_active'] ? 'checked' : '' ?>>
        <label for="is_active">Warehouse is active</label>
    </div>
    <div class="form-actions">
        <button class="btn btn--primary" type="submit">Save</button>
        <a class="btn btn--ghost" href="/warehouses">Cancel</a>
    </div>
</form>
