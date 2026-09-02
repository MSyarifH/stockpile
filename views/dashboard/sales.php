<?php
/** @var callable $e @var \App\Entity\AuthenticatedUser $user
 *  @var array<string,int> $sales_orders @var float $order_value
 *  @var int $pending_approval @var int $drafts @var list<string> $salesStatuses */
$mine = array_sum($sales_orders);
?>
<h1 class="page-title">Sales dashboard</h1>
<p class="muted">Your own orders only — <?= $e($user->name) ?>.</p>

<div class="tiles">
    <div class="tile">
        <p class="tile__label">Your orders</p>
        <p class="tile__value"><?= (int) $mine ?></p>
        <p class="tile__note"><a href="/sales-orders">View all</a></p>
    </div>
    <div class="tile">
        <p class="tile__label">Order value</p>
        <p class="tile__value">Rp <?= number_format($order_value, 0, ',', '.') ?></p>
        <p class="tile__note">Excludes cancelled orders</p>
    </div>
    <div class="tile <?= $drafts > 0 ? 'tile--warn' : '' ?>">
        <p class="tile__label">Drafts to submit</p>
        <p class="tile__value"><?= (int) $drafts ?></p>
        <p class="tile__note"><a href="/sales-orders?status=Draft">Open drafts</a></p>
    </div>
    <div class="tile">
        <p class="tile__label">Awaiting approval</p>
        <p class="tile__value"><?= (int) $pending_approval ?></p>
        <p class="tile__note">An Admin must review these</p>
    </div>
</div>

<section class="card">
    <h2 class="card__title">Your orders by status</h2>
    <table class="table">
        <thead><tr><th scope="col">Status</th><th scope="col">Count</th></tr></thead>
        <tbody>
        <?php foreach ($salesStatuses as $status) : ?>
            <tr>
                <td data-label="Status">
                    <a href="/sales-orders?status=<?= $e($status) ?>"><?= $e($status) ?></a>
                </td>
                <td data-label="Count"><?= (int) ($sales_orders[$status] ?? 0) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
