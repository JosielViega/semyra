<?php

declare(strict_types=1);

/** @var string $content */
/** @var string $title */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Modelo PHP') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <script src="/assets/js/app.js" defer></script>
</head>
<body>
    <header class="site-header">
        <a href="/">Modelo PHP</a>
        <a href="/health">Health</a>
    </header>
    <main class="container">
        <?= $content ?>
    </main>
</body>
</html>
