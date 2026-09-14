<?php
/** @var callable $e @var \App\Entity\PartnerType $type @var list<\App\Entity\BusinessPartner> $partners @var string $csrfToken */
$base = '/' . $type->urlSegment();
?>
<div class="page-header">
    <h1 class="page-title"><?= $e($type->pluralLabel()) ?></h1>
    <a class="btn btn--primary" href="<?= $e($base) ?>/create">Add <?= $e(strtolower($type->label())) ?></a>
</div>

<?php if ($partners === []) : ?>
    <p class="empty">No <?= $e(strtolower($type->pluralLabel())) ?> yet.</p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th scope="col">Name</th><th scope="col">Contact</th><th scope="col">Address</th>
                <th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($partners as $partner) : ?>
                <tr>
                    <td data-label="Name"><?= $e($partner->name) ?></td>
                    <td data-label="Contact"><?= $e($partner->contact) ?></td>
                    <td data-label="Address"><?= $e($partner->address) ?></td>
                    <td data-label="Status">
                        <span class="badge <?= $partner->isActive ? 'badge--ok' : 'badge--off' ?>">
                            <?= $partner->isActive ? 'Active' : 'Inactive' ?></span>
                    </td>
                    <td data-label="Actions" class="row-actions">
                        <a class="btn btn--small" href="<?= $e($base) ?>/<?= (int) $partner->id ?>/edit"><?= $icon('pencil') ?>Edit</a>
                        <form method="post" action="<?= $e($base) ?>/<?= (int) $partner->id ?>/active">
                            <input type="hidden" name="_token" value="<?= $e($csrfToken ?? '') ?>">
                            <input type="hidden" name="activate" value="<?= $partner->isActive ? '0' : '1' ?>">
                            <button class="btn btn--small btn--ghost" type="submit">
                                <?= $partner->isActive ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
