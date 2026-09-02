<?php
/** @var callable $e @var \App\Entity\AuthenticatedUser $user */
?>
<h1 class="page-title">Warehouse dashboard</h1>
<p class="muted">Signed in as <?= $e($user->name) ?>.</p>

<div class="grid">
    <section class="card card--pending">
        <h2 class="card__title">Coming in later phases</h2>
        <ul class="list">
            <li>Goods receipt and goods issue queues</li>
            <li>Low-stock products per warehouse</li>
        </ul>
    </section>
</div>
