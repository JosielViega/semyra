<?php

declare(strict_types=1);

/** @var string $appName */
/** @var string $csrfField */
/** @var array $flashes */
?>
<section class="hero">
    <p class="eyebrow">Assista junto.</p>
    <h1><?= e($appName) ?></h1>
    <p class="hero-copy">Crie uma sala e assista com seus amigos, mesmo à distância.</p>
</section>

<?php foreach ($flashes as $type => $messages): ?>
    <?php foreach ($messages as $message): ?>
        <p class="flash flash-<?= e($type) ?>" role="status"><?= e($message) ?></p>
    <?php endforeach; ?>
<?php endforeach; ?>

<section class="room-preview" aria-labelledby="room-preview-title">
    <div>
        <p class="eyebrow">Nova sala</p>
        <h2 id="room-preview-title">Sua próxima sessão começa aqui</h2>
        <p>Crie uma sala vazia e escolha o que transmitir depois de entrar.</p>
    </div>
    <form class="room-preview-controls" method="post" action="/rooms">
        <?= $csrfField ?>
        <button type="submit">Criar sala</button>
    </form>
</section>
