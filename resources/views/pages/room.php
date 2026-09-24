<?php

declare(strict_types=1);

/** @var array $room */
?>
<section class="room-page">
    <p class="eyebrow">Sala</p>
    <h1>Semyra</h1>
    <p class="room-code-label">Código da sala</p>
    <p class="room-code"><?= e($room['code']) ?></p>

    <div class="player-placeholder">
        <h2>Live vinculada à sala</h2>
        <p>O player será adicionado na próxima etapa.</p>
    </div>
</section>
