<?php
/** @var callable $e @var \App\Entity\User $account @var string $csrfToken @var array<string,string> $errors */
?>
<h1 class="page-title">My profile</h1>
<p class="muted">Your own account. Only an Admin can change your name, email or role.</p>

<div class="grid">
    <section class="card">
        <h2 class="card__title">Account</h2>
        <dl class="detail">
            <dt>Name</dt><dd><?= $e($account->name) ?></dd>
            <dt>Email</dt><dd><?= $e($account->email) ?></dd>
            <dt>Role</dt><dd><span class="badge badge--role"><?= $e($account->role->label()) ?></span></dd>
            <dt>Status</dt>
            <dd><span class="badge <?= $account->isActive ? 'badge--ok' : 'badge--off' ?>">
                <?= $account->isActive ? 'Active' : 'Inactive' ?></span></dd>
        </dl>
    </section>

    <section class="card">
        <h2 class="card__title">Change password</h2>
        <form method="post" action="/profile/password" novalidate>
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">

            <div class="field">
                <label for="current_password">Current password</label>
                <input id="current_password" name="current_password" type="password"
                       required autocomplete="current-password">
                <?php if (isset($errors['current_password'])) : ?>
                    <p class="field__error"><?= $e($errors['current_password']) ?></p>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="password">New password</label>
                <input id="password" name="password" type="password" required minlength="8"
                       autocomplete="new-password">
                <?php if (isset($errors['password'])) : ?>
                    <p class="field__error"><?= $e($errors['password']) ?></p>
                <?php endif; ?>
                <p class="field__hint">Minimum 8 characters, and different from your current one.</p>
            </div>

            <button class="btn btn--primary" type="submit">Change password</button>
        </form>
    </section>
</div>
