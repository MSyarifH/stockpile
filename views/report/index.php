<?php
/** @var callable $e @var string $from @var string $to @var bool $canExportStock @var string|null $error */
?>
<h1 class="page-title">Reports</h1>
<p class="muted">
    Exports are generated from the same aggregation queries as the dashboard, so a file can
    never disagree with what the screen showed.
</p>

<?php if ($error !== null) : ?>
    <p class="alert alert--error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>

<div class="grid">
    <?php if ($canExportStock) : ?>
        <section class="card">
            <h2 class="card__title">Stock movements</h2>
            <p>Every ledger entry in the range: receipts, issues and adjustments.</p>
            <form method="get" action="/reports/stock-movements">
                <div class="field">
                    <label for="from-stock">From</label>
                    <input id="from-stock" name="from" type="date" required value="<?= $e($from) ?>">
                </div>
                <div class="field">
                    <label for="to-stock">To</label>
                    <input id="to-stock" name="to" type="date" required value="<?= $e($to) ?>">
                </div>
                <button class="btn btn--primary" type="submit">Download CSV</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2 class="card__title">Order status</h2>
        <p>Sales orders in the range with their status, approver and total.</p>
        <form method="get" action="/reports/order-status">
            <div class="field">
                <label for="from-order">From</label>
                <input id="from-order" name="from" type="date" required value="<?= $e($from) ?>">
            </div>
            <div class="field">
                <label for="to-order">To</label>
                <input id="to-order" name="to" type="date" required value="<?= $e($to) ?>">
            </div>
            <button class="btn btn--primary" type="submit">Download CSV</button>
        </form>
    </section>
</div>
