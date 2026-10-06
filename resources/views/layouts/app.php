<?php

declare(strict_types=1);

/** @var string $content */
/** @var string $title */
/** @var null|array{id: int, display_name: string, email: string} $currentUser */
$currentUser = $currentUser ?? null;
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Semyra') ?></title>
    <link rel="stylesheet" href="/assets/css/tokens.css?v=8b-identity">
    <link rel="stylesheet" href="/assets/css/app.css?v=my-rooms-1">
    <script src="/assets/js/desktop-bridge.js?v=11c-host-session" defer></script>
    <script src="/assets/js/app.js?v=8b-identity" defer></script>
</head>
<body>
    <header class="site-header">
        <div class="site-header-inner">
            <a class="site-brand" href="/" aria-label="Semyra — início"><img src="/assets/images/logo_semyra_symbol.png?v=8b-polish-2" alt=""><strong>SEMYRA</strong></a>
            <nav class="site-navigation" aria-label="Navegação principal">
                <?php if (is_array($currentUser)): ?>
                    <span class="account-greeting">Olá, <?= e($currentUser['display_name']) ?></span>
                    <a class="account-link" href="/rooms">Minhas salas</a>
                    <form class="account-logout" method="post" action="/logout">
                        <?= $csrfField ?? '' ?>
                        <button type="submit">Sair</button>
                    </form>
                <?php else: ?>
                    <a class="account-link" href="/login">Entrar</a>
                    <a class="account-link account-link-primary" href="/register">Criar conta</a>
                <?php endif; ?>
                <a class="health-link" href="/health"><span aria-hidden="true"></span>Status</a>
            </nav>
        </div>
    </header>
    <main class="site-main">
        <?= $content ?>
    </main>
</body>
</html>
