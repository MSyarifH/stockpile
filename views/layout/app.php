<?php
/**
 * @var callable $e
 * @var callable $icon
 * @var string $title
 * @var string $content
 * @var \App\Entity\AuthenticatedUser|null $user
 * @var list<array{type:string,message:string}> $flashes
 * @var string $csrfToken
 * @var string $currentPath
 */
$user = $user ?? null;
$flashes = $flashes ?? [];
$currentPath = $currentPath ?? '/';

/**
 * Marks the link for the section being viewed.
 *
 * Matches on prefix, not equality: /products/12/edit is still the Products
 * section, and a user who has drilled into a record should not watch the
 * navigation forget where they are. '/' is compared exactly so it does not
 * match everything.
 */
$isCurrent = static function (string $href) use ($currentPath): bool {
    return $href === '/'
        ? $currentPath === '/'
        : $currentPath === $href || str_starts_with($currentPath, $href . '/');
};

/** @param list<array{0:string,1:string,2:string}> $links */
$renderLinks = static function (array $links) use ($e, $icon, $isCurrent): string {
    $html = '';
    foreach ($links as [$href, $iconName, $label]) {
        $current = $isCurrent($href);
        $html .= sprintf(
            '<a href="%s"%s>%s%s</a>',
            $e($href),
            $current ? ' aria-current="page"' : '',
            $icon($iconName),
            $e($label),
        );
    }
    return $html;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title) ?> · Stockpile</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<?php require_once __DIR__ . '/../partial/icon-sprite.php'; ?>
<a class="skip-link" href="#main">Skip to content</a>

<header class="topbar">
    <div class="topbar__inner">
        <a class="brand" href="/dashboard">Stockpile</a>

        <?php if ($user !== null) : ?>
            <?php
            // Top level is capped at five entries. Everything an Admin manages
            // but rarely opens mid-task -- the reference data -- moves into one
            // grouped menu below. Ten flat links wrapped onto three rows even on
            // a 1440px screen and pushed the account controls onto a fourth,
            // which is a navigation that has stopped ranking anything.
            $primary = [['/dashboard', 'layout-dashboard', 'Dashboard']];
            $primary[] = ['/products', 'package', 'Products'];
            if (!$user->is(\App\Entity\Role::Sales)) {
                $primary[] = ['/purchase-orders', 'truck', 'Purchases'];
            }
            $primary[] = ['/sales-orders', 'shopping-cart', 'Sales'];
            $primary[] = ['/reports', 'file-text', 'Reports'];

            $reference = $user->isAdmin() ? [
                ['/categories', 'tags', 'Categories'],
                ['/warehouses', 'warehouse', 'Warehouses'],
                ['/suppliers', 'factory', 'Suppliers'],
                ['/customers', 'user', 'Customers'],
                ['/users', 'users', 'Users'],
            ] : [];

            $referenceIsCurrent = false;
            foreach ($reference as [$href]) {
                $referenceIsCurrent = $referenceIsCurrent || $isCurrent($href);
            }
            ?>
            <nav class="nav" aria-label="Main">
                <?= $renderLinks($primary) ?>

                <?php if ($reference !== []) : ?>
                    <!-- <details> rather than a scripted dropdown: it opens on click and on
                         Enter, closes on Escape, and is announced as expanded or collapsed,
                         all without JavaScript. nav.js only adds closing when the focus or
                         the pointer leaves, which is polish the element does not provide. -->
                    <!-- Deliberately NOT opened when one of its links is the current page:
                         the panel is absolutely positioned and would cover the table the
                         user just navigated to. The highlighted summary already says
                         which group they are in. -->
                    <details class="menu">
                        <summary<?= $referenceIsCurrent ? ' aria-current="true"' : '' ?>>
                            <?= $icon('tags') ?>Master data<?= $icon('chevron-down') ?>
                        </summary>
                        <div class="menu__panel"><?= $renderLinks($reference) ?></div>
                    </details>
                <?php endif; ?>
            </nav>

            <form class="topbar__user" method="post" action="/logout">
                <input type="hidden" name="_token" value="<?= $e($csrfToken ?? '') ?>">
                <a class="topbar__account" href="/profile">
                    <span class="badge badge--role"><?= $e($user->role->label()) ?></span>
                    <span class="topbar__name"><?= $e($user->name) ?></span>
                </a>
                <button class="btn btn--ghost btn--small" type="submit">
                    <?= $icon('log-out') ?><span class="topbar__signout-label">Sign out</span>
                </button>
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
        <output class="alert alert--<?= $e($flash['type']) ?>">
            <?= $icon($flashIcon) ?><?= $e($flash['message']) ?>
        </output>
    <?php endforeach; ?>

    <?= $content ?>
</main>

<footer class="footer">
    <p>Inventory &amp; Order Management System</p>
</footer>
<script src="/assets/nav.js" defer></script>
<script src="/assets/form-validate.js" defer></script>
</body>
</html>
