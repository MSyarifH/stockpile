<?php
/**
 * @var callable $e
 * @var callable $icon
 * @var string $title
 * @var string $csrfToken
 * @var string $email
 * @var string|null $error
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title) ?> · Stockpile</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="page-centred">
<?php require_once __DIR__ . '/../partial/icon-sprite.php'; ?>
<main class="card card--auth">
    <h1 class="card__title">Sign in</h1>
    <p class="card__subtitle">Inventory &amp; Order Management System</p>

    <?php if ($error !== null) : ?>
        <p class="alert alert--error" role="alert"><?= $icon('triangle-alert') ?><?= $e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/login" novalidate>
        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">

        <div class="field">
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" required autocomplete="username"
                   value="<?= $e($email) ?>" autofocus>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        </div>

        <button class="btn btn--primary btn--block" type="submit">Sign in</button>
    </form>
</main>
<script src="/assets/form-validate.js" defer></script>
</body>
</html>
