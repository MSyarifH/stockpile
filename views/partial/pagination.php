<?php
/**
 * Shared pager. Used by every list page so the behaviour — and the 10-per-page
 * rule — is defined once (FIND-01).
 *
 * @var callable $e
 * @var callable $icon
 * @var \App\Support\Page<mixed> $page
 * @var \App\Support\QueryString $query
 */
if ($page->totalPages() <= 1) {
    return;
}
$current = $page->currentPage;
?>
<nav class="pager" aria-label="Pagination">
    <p class="pager__status" role="status">
        Showing <?= $page->from() ?>–<?= $page->to() ?> of <?= $page->total ?>
    </p>

    <ul class="pager__list">
        <li>
            <?php if ($page->hasPrevious()) : ?>
                <a class="btn btn--small" href="<?= $e($query->with(['page' => $current - 1])) ?>"
                   rel="prev"><?= $icon('chevron-left') ?>Previous</a>
            <?php else : ?>
                <span class="btn btn--small btn--disabled" aria-disabled="true"><?= $icon('chevron-left') ?>Previous</span>
            <?php endif; ?>
        </li>

        <?php for ($number = 1; $number <= $page->totalPages(); $number++) : ?>
            <li>
                <?php if ($number === $current) : ?>
                    <span class="btn btn--small btn--primary" aria-current="page"><?= $number ?></span>
                <?php else : ?>
                    <a class="btn btn--small" href="<?= $e($query->with(['page' => $number])) ?>"><?= $number ?></a>
                <?php endif; ?>
            </li>
        <?php endfor; ?>

        <li>
            <?php if ($page->hasNext()) : ?>
                <a class="btn btn--small" href="<?= $e($query->with(['page' => $current + 1])) ?>"
                   rel="next">Next<?= $icon('chevron-right') ?></a>
            <?php else : ?>
                <span class="btn btn--small btn--disabled" aria-disabled="true">Next<?= $icon('chevron-right') ?></span>
            <?php endif; ?>
        </li>
    </ul>
</nav>
