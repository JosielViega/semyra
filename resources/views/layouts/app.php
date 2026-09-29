<?php

declare(strict_types=1);

/** @var string $content */
/** @var string $title */
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Semyra') ?></title>
    <link rel="stylesheet" href="/assets/css/tokens.css?v=8b-identity">
    <link rel="stylesheet" href="/assets/css/app.css?v=8b-identity">
    <script src="/assets/js/app.js?v=8b-identity" defer></script>
</head>
<body>
    <header class="site-header">
        <div class="site-header-inner">
            <a class="site-brand" href="/" aria-label="Semyra — início"><img src="/assets/images/logo_semyra_symbol.png?v=8b-polish-2" alt=""><strong>SEMYRA</strong></a>
            <nav class="site-navigation" aria-label="Navegação principal">
                <a class="health-link" href="/health"><span aria-hidden="true"></span>Status</a>
            </nav>
        </div>
    </header>
    <main class="site-main">
        <?= $content ?>
    </main>
</body>
</html>
