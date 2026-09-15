<?php
/** @var callable $e @var int $status @var string $message */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= (int) $status ?> · Stockpile</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="page-centred">
<main class="card card--auth">
    <h1 class="card__title"><?= (int) $status ?></h1>
    <p><?= $e($message) ?></p>
    <a class="btn btn--primary btn--block" href="/dashboard">Back to dashboard</a>
</main>
</body>
</html>
