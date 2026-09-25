<?php

declare(strict_types=1);

/** @var array $room */
/** @var null|array{participant_key: string, display_name: string} $identity */
/** @var list<array{name: string, is_you: bool}> $participants */
/** @var null|array{source: string, youtube_video_id: null|string, revision: int, owner_name: string, is_owner: bool} $transmission */
/** @var bool $debug */
/** @var array $flashes */
/** @var string $csrfField */
/** @var string $csrfToken */
?>
<?php if ($identity === null): ?>
    <main class="room-shell room-shell-join">
        <section class="room-join-panel" aria-labelledby="room-join-title">
            <a class="room-wordmark" href="/">Semyra</a>
            <p class="room-kicker">Código da sala</p>
            <p class="room-code"><?= e($room['code']) ?></p>

            <?php foreach ($flashes as $type => $messages): ?>
                <?php foreach ($messages as $message): ?>
                    <p class="room-flash room-flash-<?= e($type) ?>" role="status"><?= e($message) ?></p>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <h1 id="room-join-title">Entre na sala</h1>
            <p>Escolha como as outras pessoas verão você nesta sessão.</p>
            <form action="/room/<?= e($room['code']) ?>/join" method="post">
                <?= $csrfField ?>
                <label for="display-name">Seu apelido</label>
                <input id="display-name" name="display_name" type="text" maxlength="30" autocomplete="nickname" required>
                <button type="submit">Entrar na sala</button>
            </form>
        </section>
    </main>
<?php else: ?>
    <main
        class="room-shell<?= $transmission !== null ? ' has-transmission' : '' ?>"
        data-room-shell
        data-room-presence
        data-presence-url="/room/<?= e($room['code']) ?>/presence"
        data-csrf-token="<?= e($csrfToken) ?>"
        data-initial-source="<?= e($transmission['source'] ?? '') ?>"
        data-initial-video-id="<?= e($transmission['youtube_video_id'] ?? '') ?>"
        data-initial-revision="<?= e((string) ($transmission['revision'] ?? '')) ?>"
        data-initial-owner-name="<?= e($transmission['owner_name'] ?? '') ?>"
        data-initial-is-owner="<?= ($transmission['is_owner'] ?? false) ? '1' : '0' ?>"
    >
        <div class="room-stage" aria-hidden="true">
            <div id="room-player-mount" class="room-player-frame"></div>
            <div class="room-stage-shade"></div>
        </div>

        <section class="room-empty-state" data-room-empty-state<?= $transmission !== null ? ' hidden' : '' ?>>
            <span class="room-empty-mark">S</span>
            <h1>Semyra</h1>
            <p>Nenhuma transmissão ativa</p>
            <button type="button" data-open-transmission>Iniciar transmissão</button>
        </section>

        <div class="room-hud" data-room-hud>
            <header class="room-hud-top">
                <div>
                    <a class="room-wordmark" href="/">Semyra</a>
                    <span class="room-code-compact">Sala <?= e($room['code']) ?></span>
                </div>
                <nav class="room-hud-actions" aria-label="Ações da sala">
                    <button type="button" class="room-icon-button" data-panel-toggle="participants" aria-label="Mostrar participantes" aria-expanded="false"><span aria-hidden="true">●●</span><strong id="room-participant-count"><?= count($participants) ?></strong></button>
                    <button type="button" class="room-icon-button" data-panel-toggle="share" aria-label="Compartilhar sala" aria-expanded="false">↗</button>
                    <button type="button" class="room-icon-button" data-open-transmission aria-label="Iniciar ou trocar transmissão">＋</button>
                </nav>
            </header>

            <footer class="room-hud-bottom">
                <div class="room-owner-copy" aria-live="polite">
                    <span data-room-owner><?= $transmission !== null ? e(($transmission['is_owner'] ? 'Você' : $transmission['owner_name']) . ' está transmitindo') : 'Sem transmissão ativa' ?></span>
                    <span class="room-player-status" id="youtube-player-status" role="status" aria-live="polite"></span>
                </div>
                <div class="room-local-controls">
                    <button type="button" class="room-icon-button" data-mute-toggle aria-label="Ativar som">🔇</button>
                    <button type="button" class="room-icon-button" data-fullscreen-toggle aria-label="Entrar em tela cheia">⛶</button>
                </div>
            </footer>
        </div>

        <aside class="room-panel room-panel-participants" data-room-panel="participants" hidden aria-labelledby="participants-title">
            <div class="room-panel-heading"><h2 id="participants-title">Na sala</h2><button type="button" class="room-close-button" data-panel-close aria-label="Fechar participantes">×</button></div>
            <ul id="room-participant-list" class="room-participant-list">
                <?php foreach ($participants as $participant): ?>
                    <li><span><?= e($participant['name']) ?></span><?php if ($participant['is_you']): ?><strong> (você)</strong><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        </aside>

        <aside class="room-panel room-panel-share" data-room-panel="share" data-room-share data-room-code="<?= e($room['code']) ?>" hidden aria-labelledby="share-title">
            <div class="room-panel-heading"><h2 id="share-title">Compartilhar sala</h2><button type="button" class="room-close-button" data-panel-close aria-label="Fechar compartilhamento">×</button></div>
            <label for="room-share-url">Link da sala</label>
            <input id="room-share-url" type="url" readonly>
            <div class="room-panel-actions"><button id="room-copy-link" type="button">Copiar link</button><button id="room-native-share" type="button" hidden>Compartilhar</button></div>
            <p id="room-share-status" class="room-panel-status" role="status" aria-live="polite"></p>
        </aside>

        <?php if ($debug): ?>
            <aside class="room-debug" data-room-telemetry aria-labelledby="room-debug-title">
                <h2 id="room-debug-title">Diagnóstico</h2>
                <ul id="room-telemetry-list">
                    <?php foreach ($participants as $participant): ?>
                        <li><strong><?= e($participant['name']) ?><?php if ($participant['is_you']): ?> (você)<?php endif; ?></strong><span>Aguardando player</span></li>
                    <?php endforeach; ?>
                </ul>
                <p id="room-presence-status" role="status" aria-live="polite"></p>
            </aside>
        <?php else: ?>
            <p id="room-presence-status" class="room-visually-hidden" role="status" aria-live="polite"></p>
        <?php endif; ?>

        <div class="room-flash-stack" aria-live="polite">
            <?php foreach ($flashes as $type => $messages): ?>
                <?php foreach ($messages as $message): ?>
                    <p class="room-flash room-flash-<?= e($type) ?>" role="status"><?= e($message) ?></p>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <form class="room-end-form" action="/room/<?= e($room['code']) ?>/transmission/end" method="post" data-end-transmission<?= !($transmission['is_owner'] ?? false) ? ' hidden' : '' ?>>
            <?= $csrfField ?>
            <button type="submit">Encerrar transmissão</button>
        </form>

        <dialog id="room-transmission-dialog" class="room-dialog" aria-labelledby="transmission-dialog-title">
            <form method="post" action="/room/<?= e($room['code']) ?>/transmission">
                <?= $csrfField ?>
                <div class="room-dialog-heading">
                    <div><p class="room-kicker">YouTube</p><h2 id="transmission-dialog-title">Iniciar transmissão</h2></div>
                    <button type="button" class="room-close-button" data-close-transmission aria-label="Cancelar">×</button>
                </div>
                <p class="room-replace-warning" data-replace-warning<?= $transmission === null ? ' hidden' : '' ?>><strong data-replace-owner><?= e($transmission['owner_name'] ?? 'Participante') ?></strong> está transmitindo. Iniciar sua transmissão substituirá a transmissão atual.</p>
                <label for="youtube-url">Link do YouTube</label>
                <input id="youtube-url" name="youtube_url" type="url" maxlength="2048" placeholder="https://youtube.com/watch?v=..." required>
                <div class="room-dialog-actions"><button type="button" class="room-secondary-button" data-close-transmission>Cancelar</button><button type="submit">Iniciar minha transmissão</button></div>
            </form>
        </dialog>
    </main>

    <script src="/assets/js/room-player.js" defer></script>
    <script src="/assets/js/room-share.js" defer></script>
    <?php if ($debug): ?><script src="/assets/js/room-telemetry.js" defer></script><?php endif; ?>
    <script src="/assets/js/room-shell.js" defer></script>
    <script src="/assets/js/room-presence.js" defer></script>
<?php endif; ?>
