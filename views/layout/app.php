<?php
/**
 * @var callable $e
 * @var string $title
 * @var string $content
 * @var \App\Entity\AuthenticatedUser|null $user
 * @var list<array{type:string,message:string}> $flashes
 * @var string $csrfToken
 */
$user = $user ?? null;
$flashes = $flashes ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title) ?> · IOMS</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<header class="topbar">
    <div class="topbar__inner">
        <a class="brand" href="/dashboard">IOMS</a>

        <?php if ($user !== null) : ?>
            <nav class="nav" aria-label="Main">
                <a href="/dashboard">Dashboard</a>
                <?php if ($user->isAdmin()) : ?>
                    <a href="/users">Users</a>
                <?php endif; ?>
            </nav>

            <form class="topbar__user" method="post" action="/logout">
                <input type="hidden" name="_token" value="<?= $e($csrfToken ?? '') ?>">
                <span class="badge badge--role"><?= $e($user->role->label()) ?></span>
                <span class="topbar__name"><?= $e($user->name) ?></span>
                <button class="btn btn--ghost" type="submit">Sign out</button>
            </form>
        <?php endif; ?>
    </div>
</header>

<main id="main" class="container">
    <?php foreach ($flashes as $flash) : ?>
        <p class="alert alert--<?= $e($flash['type']) ?>" role="status"><?= $e($flash['message']) ?></p>
    <?php endforeach; ?>

    <?= $content ?>
</main>

<footer class="footer">
    <p>Inventory &amp; Order Management System</p>
</footer>
</body>
</html>
