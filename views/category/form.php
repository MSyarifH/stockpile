<?php
/** @var callable $e @var string $csrfToken @var \App\Entity\Category|null $editing
 *  @var array<string,string> $values @var array<string,string> $errors */
$action = $editing === null ? '/categories' : '/categories/' . (int) $editing->id;
?>
<h1 class="page-title"><?= $editing === null ? 'Add category' : 'Edit category' ?></h1>

<form class="card card--form" method="post" action="<?= $e($action) ?>" novalidate>
    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
    <div class="field">
        <label for="name">Name</label>
        <input id="name" name="name" type="text" required maxlength="120" value="<?= $e($values['name']) ?>">
        <?php if (isset($errors['name'])) : ?><p class="field__error"><?= $e($errors['name']) ?></p><?php endif; ?>
    </div>
    <div class="field">
        <label for="description">Description</label>
        <input id="description" name="description" type="text" maxlength="255" value="<?= $e($values['description']) ?>">
    </div>
    <div class="form-actions">
        <button class="btn btn--primary" type="submit">Save</button>
        <a class="btn btn--ghost" href="/categories">Cancel</a>
    </div>
</form>
