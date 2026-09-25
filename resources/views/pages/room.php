<?php

declare(strict_types=1);

/** @var array $room */
/** @var null|array{participant_key: string, display_name: string} $identity */
/** @var list<array{name: string, is_you: bool}> $participants */
/** @var array $flashes */
/** @var string $csrfField */
/** @var string $csrfToken */
?>
<section class="room-page">
    <p class="eyebrow">Sala</p>
    <h1>Semyra</h1>
    <p class="room-code-label">Código da sala</p>
    <p class="room-code"><?= e($room['code']) ?></p>

    <?php foreach ($flashes as $type => $messages): ?>
        <?php foreach ($messages as $message): ?>
            <p class="flash flash-<?= e($type) ?>" role="status"><?= e($message) ?></p>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?php if ($identity === null): ?>
        <section class="room-join card" aria-labelledby="room-join-title">
            <h2 id="room-join-title">Entre na sala</h2>
            <p>Escolha como as outras pessoas verão você nesta sessão.</p>
            <form action="/room/<?= e($room['code']) ?>/join" method="post">
                <?= $csrfField ?>
                <label for="display-name">Seu apelido</label>
                <input id="display-name" name="display_name" type="text" maxlength="30" autocomplete="nickname" required>
                <button type="submit">Entrar na sala</button>
            </form>
        </section>
    <?php else: ?>
        <p class="room-identity">Você entrou como <strong><?= e($identity['display_name']) ?></strong>.</p>

        <div class="youtube-player-shell">
            <div id="youtube-player" data-video-id="<?= e($room['youtube_video_id']) ?>"></div>
        </div>
        <p id="youtube-player-status" class="youtube-player-status" role="status" aria-live="polite">Carregando player…</p>
        <noscript><p class="youtube-player-status youtube-player-status-error">Ative o JavaScript para carregar o player do YouTube.</p></noscript>

        <section class="room-presence" data-room-presence data-presence-url="/room/<?= e($room['code']) ?>/presence" data-csrf-token="<?= e($csrfToken) ?>">
            <h2>Na sala (<span id="room-participant-count"><?= count($participants) ?></span>)</h2>
            <ul id="room-participant-list" class="room-participant-list">
                <?php foreach ($participants as $participant): ?>
                    <li>
                        <span><?= e($participant['name']) ?></span><?php if ($participant['is_you']): ?><strong> (você)</strong><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p id="room-presence-status" class="room-presence-status" role="status" aria-live="polite"></p>
        </section>

        <section class="room-telemetry" data-room-telemetry aria-labelledby="room-telemetry-title">
            <h2 id="room-telemetry-title">Reprodução observada</h2>
            <p>O Semyra está apenas medindo os players. Nenhuma sincronização automática é aplicada nesta etapa.</p>
            <ul id="room-telemetry-list" class="room-telemetry-list">
                <?php foreach ($participants as $participant): ?>
                    <li>
                        <strong><?= e($participant['name']) ?><?php if ($participant['is_you']): ?> (você)<?php endif; ?></strong>
                        <span>Aguardando dados do player</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

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
    <?php endif; ?>
</section>
<?php if ($identity !== null): ?>
    <script src="/assets/js/room-player.js" defer></script>
    <script src="/assets/js/room-share.js" defer></script>
    <script src="/assets/js/room-telemetry.js" defer></script>
    <script src="/assets/js/room-presence.js" defer></script>
<?php endif; ?>
