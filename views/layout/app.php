<?php
/**
 * @var callable $e
 * @var callable $icon
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
<?php require __DIR__ . '/../partial/icon-sprite.php'; ?>
<a class="skip-link" href="#main">Skip to content</a>

<header class="topbar">
    <div class="topbar__inner">
        <a class="brand" href="/dashboard">IOMS</a>

        <?php if ($user !== null) : ?>
            <nav class="nav" aria-label="Main">
                <a href="/dashboard"><?= $icon('layout-dashboard') ?>Dashboard</a>
                <a href="/products"><?= $icon('package') ?>Products</a>
                <?php if (!$user->is(\App\Entity\Role::Sales)) : ?>
                    <a href="/purchase-orders"><?= $icon('truck') ?>Purchases</a>
                <?php endif; ?>
                <a href="/sales-orders"><?= $icon('shopping-cart') ?>Sales</a>
                <a href="/reports"><?= $icon('file-text') ?>Reports</a>
                <?php if ($user->isAdmin()) : ?>
                    <a href="/categories"><?= $icon('tags') ?>Categories</a>
                    <a href="/warehouses"><?= $icon('warehouse') ?>Warehouses</a>
                    <a href="/suppliers"><?= $icon('factory') ?>Suppliers</a>
                    <a href="/customers"><?= $icon('user') ?>Customers</a>
                    <a href="/users"><?= $icon('users') ?>Users</a>
                <?php endif; ?>
            </nav>

            <form class="topbar__user" method="post" action="/logout">
                <input type="hidden" name="_token" value="<?= $e($csrfToken ?? '') ?>">
                <span class="badge badge--role"><?= $e($user->role->label()) ?></span>
                <a class="topbar__name" href="/profile"><?= $e($user->name) ?></a>
                <button class="btn btn--ghost" type="submit"><?= $icon('log-out') ?>Sign out</button>
            </form>
        <?php endif; ?>
    </div>
</header>

<main id="main" class="container">
    <?php foreach ($flashes as $flash) : ?>
        <?php
        // The icon reinforces the colour it sits on; colour alone does not
        // distinguish success from failure for every reader (UI-01).
        $flashIcon = match ($flash['type']) {
            'success' => 'circle-check',
            'error' => 'triangle-alert',
            default => 'info',
        };
        ?>
        <p class="alert alert--<?= $e($flash['type']) ?>" role="status">
            <?= $icon($flashIcon) ?><?= $e($flash['message']) ?>
        </p>
    <?php endforeach; ?>

    <?= $content ?>
</main>

<footer class="footer">
    <p>Inventory &amp; Order Management System</p>
</footer>
<script src="/assets/form-validate.js" defer></script>
</body>
</html>
