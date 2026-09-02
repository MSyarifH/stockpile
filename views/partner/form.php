<?php
/** @var callable $e @var \App\Entity\PartnerType $type @var string $csrfToken
 *  @var \App\Entity\BusinessPartner|null $editing @var array<string,mixed> $values @var array<string,string> $errors */
$base = '/' . $type->urlSegment();
$action = $editing === null ? $base : $base . '/' . (int) $editing->id;
?>
<h1 class="page-title"><?= $editing === null ? 'Add' : 'Edit' ?> <?= $e(strtolower($type->label())) ?></h1>

<form class="card card--form" method="post" action="<?= $e($action) ?>" novalidate>
    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
    <div class="field">
        <label for="name">Name</label>
        <input id="name" name="name" type="text" required maxlength="150" value="<?= $e($values['name']) ?>">
        <?php if (isset($errors['name'])) : ?><p class="field__error"><?= $e($errors['name']) ?></p><?php endif; ?>
    </div>
    <div class="field">
        <label for="contact">Contact</label>
        <input id="contact" name="contact" type="text" maxlength="120" value="<?= $e($values['contact']) ?>">
    </div>
    <div class="field">
        <label for="address">Address</label>
        <input id="address" name="address" type="text" maxlength="255" value="<?= $e($values['address']) ?>">
    </div>
    <div class="field field--check">
        <input id="is_active" name="is_active" type="checkbox" value="1" <?= $values['is_active'] ? 'checked' : '' ?>>
        <label for="is_active"><?= $e($type->label()) ?> is active</label>
    </div>
    <div class="form-actions">
        <button class="btn btn--primary" type="submit">Save</button>
        <a class="btn btn--ghost" href="<?= $e($base) ?>">Cancel</a>
    </div>
</form>
