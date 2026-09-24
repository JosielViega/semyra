<?php

declare(strict_types=1);

/** @var array $room */
?>
<section class="room-page">
    <p class="eyebrow">Sala</p>
    <h1>Semyra</h1>
    <p class="room-code-label">Código da sala</p>
    <p class="room-code"><?= e($room['code']) ?></p>

    <div class="youtube-player-shell">
        <div id="youtube-player" data-video-id="<?= e($room['youtube_video_id']) ?>"></div>
    </div>
    <p id="youtube-player-status" class="youtube-player-status" role="status" aria-live="polite">Carregando player…</p>
    <noscript><p class="youtube-player-status youtube-player-status-error">Ative o JavaScript para carregar o player do YouTube.</p></noscript>

    <section class="room-share" data-room-share data-room-code="<?= e($room['code']) ?>">
        <h2>Convide alguém</h2>
        <p>Envie o link desta sala para assistir junto.</p>

        <label for="room-share-url">Link da sala</label>
        <input id="room-share-url" class="room-share-url" type="url" readonly>

        <div class="room-share-actions">
            <button id="room-copy-link" type="button">Copiar link</button>
            <button id="room-native-share" type="button" hidden>Compartilhar</button>
        </div>
        <p id="room-share-status" class="room-share-status" role="status" aria-live="polite"></p>
    </section>
</section>
<script src="/assets/js/room-player.js" defer></script>
<script src="/assets/js/room-share.js" defer></script>
