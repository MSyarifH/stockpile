<?php
/**
 * @var callable $e
 * @var string $csrfToken
 * @var \App\Entity\User|null $editing
 * @var array{name:string,email:string,role:string,is_active:bool} $values
 * @var array<string,string> $errors
 * @var list<\App\Entity\Role> $roles
 */
$action = $editing === null ? '/users' : '/users/' . (int) $editing->id;
?>
<h1 class="page-title"><?= $editing === null ? 'Add user' : 'Edit user' ?></h1>

<form class="card card--form" method="post" action="<?= $e($action) ?>" novalidate>
    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">

    <div class="field">
        <label for="name">Name</label>
        <input id="name" name="name" type="text" required maxlength="120" value="<?= $e($values['name']) ?>">
        <?php if (isset($errors['name'])) : ?><p class="field__error"><?= $e($errors['name']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" required maxlength="190" value="<?= $e($values['email']) ?>">
        <?php if (isset($errors['email'])) : ?><p class="field__error"><?= $e($errors['email']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label for="role">Role</label>
        <select id="role" name="role" required>
            <?php foreach ($roles as $role) : ?>
                <option value="<?= $e($role->value) ?>" <?= $values['role'] === $role->value ? 'selected' : '' ?>>
                    <?= $e($role->label()) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (isset($errors['role'])) : ?><p class="field__error"><?= $e($errors['role']) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label for="password">Password<?= $editing !== null ? ' (leave blank to keep current)' : '' ?></label>
        <input id="password" name="password" type="password" autocomplete="new-password"
               <?= $editing === null ? 'required' : '' ?> minlength="8">
        <?php if (isset($errors['password'])) : ?><p class="field__error"><?= $e($errors['password']) ?></p><?php endif; ?>
        <p class="field__hint">Minimum 8 characters.</p>
    </div>

    <div class="field field--check">
        <input id="is_active" name="is_active" type="checkbox" value="1" <?= $values['is_active'] ? 'checked' : '' ?>>
        <label for="is_active">Account is active</label>
    </div>

    <div class="form-actions">
        <button class="btn btn--primary" type="submit">Save</button>
        <a class="btn btn--ghost" href="/users">Cancel</a>
    </div>
</form>
