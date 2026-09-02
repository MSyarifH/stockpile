<?php
/** @var callable $e @var \App\Entity\AuthenticatedUser $user */
?>
<h1 class="page-title">Admin dashboard</h1>
<p class="muted">Signed in as <?= $e($user->name) ?>.</p>

<div class="grid">
    <section class="card">
        <h2 class="card__title">User administration</h2>
        <p>Create and deactivate Sales and Warehouse Staff accounts.</p>
        <a class="btn btn--primary" href="/users">Manage users</a>
    </section>
    <section class="card card--pending">
        <h2 class="card__title">Coming in later phases</h2>
        <ul class="list">
            <li>Inventory value and products below reorder point</li>
            <li>Pending orders by status</li>
            <li>CSV reports</li>
        </ul>
    </section>
</div>
