<?php

declare(strict_types=1);

/** @var string $content */
/** @var string $title */
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($title ?? 'Semyra') ?></title>
    <link rel="stylesheet" href="/assets/css/room.css">
</head>
<body class="room-body">
    <?= $content ?>
</body>
</html>
