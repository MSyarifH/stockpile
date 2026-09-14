<?php
/**
 * @var callable $e
 * @var list<\App\Entity\User> $users
 * @var string $csrfToken
 */
?>
<div class="page-header">
    <h1 class="page-title">Users</h1>
    <a class="btn btn--primary" href="/users/create"><?= $icon('plus') ?>Add user</a>
</div>

<?php if ($users === []) : ?>
    <p class="empty">No users yet.</p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="visually-hidden">All user accounts</caption>
            <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Email</th>
                <th scope="col">Role</th>
                <th scope="col">Status</th>
                <th scope="col"><span class="visually-hidden">Actions</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $row) : ?>
                <tr>
                    <td data-label="Name"><?= $e($row->name) ?></td>
                    <td data-label="Email"><?= $e($row->email) ?></td>
                    <td data-label="Role"><span class="badge"><?= $e($row->role->label()) ?></span></td>
                    <td data-label="Status">
                        <span class="badge <?= $row->isActive ? 'badge--ok' : 'badge--off' ?>">
                            <?= $row->isActive ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td data-label="Actions" class="row-actions">
                        <a class="btn btn--small" href="/users/<?= (int) $row->id ?>/edit"><?= $icon('pencil') ?>Edit</a>
                        <form method="post" action="/users/<?= (int) $row->id ?>/active">
                            <input type="hidden" name="_token" value="<?= $e($csrfToken ?? '') ?>">
                            <input type="hidden" name="activate" value="<?= $row->isActive ? '0' : '1' ?>">
                            <button class="btn btn--small btn--ghost" type="submit">
                                <?= $row->isActive ? 'Deactivate' : 'Activate' ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
