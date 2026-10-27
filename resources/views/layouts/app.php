<?php

declare(strict_types=1);

/** @var string $content */
/** @var string $title */
/** @var null|array{id: int, display_name: string, email: string, is_premium: bool} $currentUser */
$currentUser = $currentUser ?? null;
$pageStyles = is_array($pageStyles ?? null) ? $pageStyles : [];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Semyra') ?></title>
    <link rel="stylesheet" href="/assets/css/tokens.css?v=8b-identity">
    <link rel="stylesheet" href="/assets/css/app.css?v=11m-premium-badge">
    <?php foreach ($pageStyles as $pageStyle): ?>
        <link rel="stylesheet" href="<?= e((string) $pageStyle) ?>">
    <?php endforeach; ?>
    <script src="/assets/js/desktop-bridge.js?v=11m-c-round11" defer></script>
    <script src="/assets/js/app.js?v=8b-identity" defer></script>
</head>
<body>
    <header class="site-header">
        <div class="site-header-inner">
            <a class="site-brand" href="/" aria-label="Semyra — início"><img src="/assets/images/logo_semyra_symbol.png?v=8b-polish-2" alt=""><strong>SEMYRA</strong></a>
            <nav class="site-navigation" aria-label="Navegação principal">
                <?php if (is_array($currentUser)): ?>
                    <a class="account-link desktop-only-link" href="/desktop/iptv" data-desktop-iptv-link hidden>Minha IPTV</a>
                    <span class="account-identity">
                        <span class="account-greeting">Olá, <?= e($currentUser['display_name']) ?></span>
                        <?php if (($currentUser['is_premium'] ?? false) === true): ?>
                            <span class="account-premium-badge" aria-label="Conta Premium" title="Conta Premium">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 7.5 7.5 12 12 5l4.5 7L21 7.5 19 18H5L3 7.5Zm3.7 8.5h10.6l.75-4-2.1 2.1L12 8l-3.95 6.1L5.95 12l.75 4Z"/></svg>
                                <span>Premium</span>
                            </span>
                        <?php endif; ?>
                    </span>
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
