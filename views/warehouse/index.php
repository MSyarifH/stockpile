<?php
/** @var callable $e @var list<\App\Entity\Warehouse> $warehouses @var string $csrfToken */
?>
<div class="page-header">
    <h1 class="page-title">Warehouses</h1>
    <a class="btn btn--primary" href="/warehouses/create"><?= $icon('plus') ?>Add warehouse</a>
</div>

<?php if ($warehouses === []) : ?>
    <p class="empty">No warehouses yet.</p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th scope="col">Name</th><th scope="col">Location</th>
                <th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($warehouses as $warehouse) : ?>
                <tr>
                    <td data-label="Name"><?= $e($warehouse->name) ?></td>
                    <td data-label="Location"><?= $e($warehouse->location) ?></td>
                    <td data-label="Status">
                        <span class="badge <?= $warehouse->isActive ? 'badge--ok' : 'badge--off' ?>">
                            <?= $warehouse->isActive ? 'Active' : 'Inactive' ?></span>
                    </td>
                    <td data-label="Actions" class="row-actions">
                        <a class="btn btn--small" href="/warehouses/<?= (int) $warehouse->id ?>/edit"><?= $icon('pencil') ?>Edit</a>
                        <form method="post" action="/warehouses/<?= (int) $warehouse->id ?>/active">
                            <input type="hidden" name="_token" value="<?= $e($csrfToken ?? '') ?>">
                            <input type="hidden" name="activate" value="<?= $warehouse->isActive ? '0' : '1' ?>">
                            <button class="btn btn--small btn--ghost" type="submit">
                                <?= $warehouse->isActive ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
