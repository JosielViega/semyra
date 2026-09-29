<?php

declare(strict_types=1);

/** @var string $appName */
/** @var string $csrfField */
/** @var array $flashes */
?>
<svg class="home-icon-sprite" aria-hidden="true" focusable="false">
    <symbol id="home-icon-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="home-icon-sync" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7h-5V2M4 17h5v5"/><path d="M5.1 9A8 8 0 0 1 18.4 5.6L20 7M4 17l1.6 1.4A8 8 0 0 0 18.9 15"/></symbol>
    <symbol id="home-icon-users" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></symbol>
    <symbol id="home-icon-youtube" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.6 7.2a2.8 2.8 0 0 0-2-2C17.8 4.7 12 4.7 12 4.7s-5.8 0-7.6.5a2.8 2.8 0 0 0-2 2A29 29 0 0 0 2 12a29 29 0 0 0 .4 4.8 2.8 2.8 0 0 0 2 2c1.8.5 7.6.5 7.6.5s5.8 0 7.6-.5a2.8 2.8 0 0 0 2-2A29 29 0 0 0 22 12a29 29 0 0 0-.4-4.8Z"/><path d="m10 15 5-3-5-3Z"/></symbol>
    <symbol id="home-icon-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></symbol>
</svg>

<section class="home-hero" aria-labelledby="home-title">
    <div class="home-hero-copy">
        <p class="eyebrow">Assista junto.</p>
        <h1 id="home-title"><?= e($appName) ?></h1>
        <p>Crie uma sala e assista com seus amigos, mesmo à distância.</p>
        <form class="home-create-form" method="post" action="/rooms">
            <?= $csrfField ?>
            <button type="submit"><svg class="home-icon" aria-hidden="true"><use href="#home-icon-plus"></use></svg><span>Criar sala</span></button>
        </form>
        <p class="home-room-lifetime">
            <?= is_array($currentUser ?? null)
                ? 'As salas que você criar conectado ficam salvas.'
                : 'Salas criadas sem conta expiram após 24 horas sem atividade.' ?>
        </p>
    </div>
    <div class="home-brand-stage" aria-hidden="true">
        <span class="home-brand-orbit"></span>
        <img src="/assets/images/logo_semyra_symbol.png?v=8b-polish-2" alt="">
    </div>
</section>

<?php foreach ($flashes as $type => $messages): ?>
    <?php foreach ($messages as $message): ?>
        <p class="flash flash-<?= e($type) ?>" role="status"><?= e($message) ?></p>
    <?php endforeach; ?>
<?php endforeach; ?>

<section class="home-benefits" aria-label="Benefícios do Semyra">
    <article><svg class="home-icon" aria-hidden="true"><use href="#home-icon-sync"></use></svg><span>Sincronização em tempo real</span></article>
    <article><svg class="home-icon" aria-hidden="true"><use href="#home-icon-users"></use></svg><span>Assista com amigos</span></article>
    <article><svg class="home-icon" aria-hidden="true"><use href="#home-icon-youtube"></use></svg><span>Transmissão via YouTube</span></article>
    <article><svg class="home-icon" aria-hidden="true"><use href="#home-icon-check"></use></svg><span>Simples e rápido</span></article>
</section>
