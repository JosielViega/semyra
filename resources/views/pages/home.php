<?php

declare(strict_types=1);

/** @var string $appName */
?>
<section class="hero">
    <p class="eyebrow">Assista junto.</p>
    <h1><?= e($appName) ?></h1>
    <p class="hero-copy">Crie uma sala e assista com seus amigos, mesmo à distância.</p>
</section>

<section class="room-preview" aria-labelledby="room-preview-title">
    <div>
        <p class="eyebrow">Em breve</p>
        <h2 id="room-preview-title">Sua próxima sessão começa aqui</h2>
        <p>A criação de salas será habilitada na próxima etapa.</p>
    </div>
    <div class="room-preview-controls" aria-label="Prévia da criação de sala">
        <label for="youtube-url">URL da Live do YouTube</label>
        <input id="youtube-url" type="url" placeholder="https://www.youtube.com/watch?v=..." disabled>
        <button type="button" disabled>Criar sala</button>
    </div>
</section>
