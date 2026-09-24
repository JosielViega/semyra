<?php

declare(strict_types=1);

/** @var string $path */
/** @var string|null $heading */
/** @var string|null $message */
?>
<section class="card">
    <p class="eyebrow">404</p>
    <h1><?= e($heading ?? 'Página não encontrada') ?></h1>
    <p><?= e($message ?? 'Nenhuma rota corresponde ao endereço informado.') ?></p>
    <p><code><?= e($path) ?></code></p>
    <a href="/">Voltar ao início</a>
</section>
