<?php
/** @var callable $e @var list<\App\Entity\Category> $categories */
?>
<div class="page-header">
    <h1 class="page-title">Categories</h1>
    <a class="btn btn--primary" href="/categories/create">Add category</a>
</div>

<?php if ($categories === []) : ?>
    <p class="empty">No categories yet. Products need one before they can be created.</p>
<?php else : ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th scope="col">Name</th><th scope="col">Description</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($categories as $category) : ?>
                <tr>
                    <td data-label="Name"><?= $e($category->name) ?></td>
                    <td data-label="Description"><?= $e($category->description) ?></td>
                    <td data-label="Actions" class="row-actions">
                        <a class="btn btn--small" href="/categories/<?= (int) $category->id ?>/edit">Edit</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
