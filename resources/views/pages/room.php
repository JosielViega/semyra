<?php

declare(strict_types=1);

/** @var array $room */
/** @var null|array{participant_key: string, display_name: string, user_id: null|int} $identity */
/** @var null|array{id: int, display_name: string} $currentUser */
/** @var list<array{name: string, is_you: bool}> $participants */
/** @var null|array{source: string, instance_id: string, youtube_video_id: null|string, revision: int, owner_name: string, is_owner: bool, media_mode: string, playback: array{state: string, position_ms: null|int, revision: int, at_live_edge: bool, live_edge_position_ms: null|int, live_sync_position_ms: null|int, live_sync_delay_ms: null|int}} $transmission */
/** @var bool $debug */
/** @var array $flashes */
/** @var string $csrfField */
/** @var string $csrfToken */
$currentUser = $currentUser ?? null;
$initialPlaying = ($transmission['playback']['state'] ?? 'playing') === 'playing';
?>
<svg class="room-icon-sprite" aria-hidden="true" focusable="false">
    <symbol id="room-icon-users" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></symbol>
    <symbol id="room-icon-share" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.59 13.51 6.83 3.98M15.41 6.51 8.59 10.49"/></symbol>
    <symbol id="room-icon-video-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 13 5 3V8l-5 3"/><rect x="3" y="6" width="13" height="12" rx="2"/><path d="M8 9v6M5 12h6"/></symbol>
    <symbol id="room-icon-sync" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7h-5V2M4 17h5v5"/><path d="M5.1 9A8 8 0 0 1 18.4 5.6L20 7M4 17l1.6 1.4A8 8 0 0 0 18.9 15"/></symbol>
    <symbol id="room-icon-play" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 5 11 7-11 7Z"/></symbol>
    <symbol id="room-icon-pause" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H6v14h3ZM18 5h-3v14h3Z"/></symbol>
    <symbol id="room-icon-volume-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4Z"/><path d="m23 9-6 6M17 9l6 6"/></symbol>
    <symbol id="room-icon-volume" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4Z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/></symbol>
    <symbol id="room-icon-maximize" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5"/></symbol>
    <symbol id="room-icon-minimize" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v5H3M16 3v5h5M8 21v-5H3M16 21v-5h5"/></symbol>
    <symbol id="room-icon-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></symbol>
    <symbol id="room-icon-copy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></symbol>
    <symbol id="room-icon-stop" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><rect x="9" y="9" width="6" height="6" rx="1"/></symbol>
    <symbol id="room-icon-enter" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 17l5-5-5-5M15 12H3M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></symbol>
</svg>
<?php if ($identity === null): ?>
    <main class="room-shell room-shell-join">
        <section class="room-join-panel" aria-labelledby="room-join-title">
            <a class="room-logo-link room-logo-link-join" href="/" aria-label="Semyra — início"><img class="room-logo room-logo-join" src="/assets/images/logo_semyra.png?v=8b-identity" alt="Semyra"></a>
            <p class="room-kicker">Código da sala</p>
            <p class="room-code"><?= e($room['code']) ?></p>

            <?php foreach ($flashes as $type => $messages): ?>
                <?php foreach ($messages as $message): ?>
                    <p class="room-flash room-flash-<?= e($type) ?>" role="status" data-room-flash data-flash-type="<?= e($type) ?>"><?= e($message) ?></p>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <h1 id="room-join-title">Entre na sala</h1>
            <?php if (is_array($currentUser)): ?>
                <p>Você entrará como <strong><?= e($currentUser['display_name']) ?></strong>.</p>
            <?php else: ?>
                <p>Escolha como as outras pessoas verão você nesta sessão.</p>
            <?php endif; ?>
            <form action="/room/<?= e($room['code']) ?>/join" method="post">
                <?= $csrfField ?>
                <?php if (!is_array($currentUser)): ?>
                    <label for="display-name">Seu apelido</label>
                    <input id="display-name" name="display_name" type="text" maxlength="30" autocomplete="nickname" required>
                <?php endif; ?>
                <button type="submit" class="room-text-button"><svg class="room-icon" aria-hidden="true"><use href="#room-icon-enter"></use></svg><span><?= is_array($currentUser) ? 'Entrar como ' . e($currentUser['display_name']) : 'Entrar na sala' ?></span></button>
            </form>
        </section>
    </main>
<?php else: ?>
    <main
        class="room-shell<?= $transmission !== null ? ' has-transmission' : '' ?>"
        data-room-shell
        data-room-presence
        data-presence-url="/room/<?= e($room['code']) ?>/presence"
        data-leave-url="/room/<?= e($room['code']) ?>/leave"
        data-playback-url="/room/<?= e($room['code']) ?>/transmission/playback"
        data-livekit-viewer-token-url="/room/<?= e($room['code']) ?>/livekit/viewer-token"
        data-desktop-host-session-url="/room/<?= e($room['code']) ?>/desktop/host-session"
        data-livekit-client-src="/assets/vendor/livekit/livekit-client.umd.js?v=2.22.3"
        data-csrf-token="<?= e($csrfToken) ?>"
        data-initial-source="<?= e($transmission['source'] ?? '') ?>"
        data-initial-instance-id="<?= e($transmission['instance_id'] ?? '') ?>"
        data-initial-video-id="<?= e($transmission['youtube_video_id'] ?? '') ?>"
        data-initial-revision="<?= e((string) ($transmission['revision'] ?? '')) ?>"
        data-initial-owner-name="<?= e($transmission['owner_name'] ?? '') ?>"
        data-initial-is-owner="<?= ($transmission['is_owner'] ?? false) ? '1' : '0' ?>"
        data-initial-media-mode="<?= e($transmission['media_mode'] ?? '') ?>"
        data-initial-playback-state="<?= e($transmission['playback']['state'] ?? '') ?>"
        data-initial-playback-position-ms="<?= e((string) ($transmission['playback']['position_ms'] ?? '')) ?>"
        data-initial-playback-revision="<?= e((string) ($transmission['playback']['revision'] ?? '')) ?>"
        data-initial-playback-at-live-edge="<?= ($transmission['playback']['at_live_edge'] ?? false) ? '1' : '0' ?>"
        data-initial-live-edge-position-ms="<?= e((string) ($transmission['playback']['live_edge_position_ms'] ?? '')) ?>"
        data-initial-live-sync-position-ms="<?= e((string) ($transmission['playback']['live_sync_position_ms'] ?? '')) ?>"
        data-initial-live-sync-delay-ms="<?= e((string) ($transmission['playback']['live_sync_delay_ms'] ?? '')) ?>"
    >
        <div class="room-stage" aria-hidden="true">
            <div id="room-player-mount" class="room-player-frame">
                <div class="room-player-source" data-youtube-player-mount></div>
                <div class="room-player-source" data-livekit-player-mount hidden></div>
            </div>
            <div class="room-stage-shade"></div>
        </div>

        <section class="room-empty-state" data-room-empty-state<?= $transmission !== null ? ' hidden' : '' ?>>
            <img class="room-logo room-logo-empty" src="/assets/images/logo_semyra_symbol.png?v=8b-polish-2" alt="Semyra">
            <h1>Nenhuma transmissão ativa</h1>
            <p>Seja o primeiro a iniciar uma transmissão<br>e assista junto com seus amigos.</p>
            <button type="button" class="room-text-button" data-open-transmission><svg class="room-icon" aria-hidden="true"><use href="#room-icon-video-plus"></use></svg><span>Iniciar transmissão</span></button>
        </section>

        <div class="room-hud" data-room-hud>
            <header class="room-hud-top">
                <div class="room-hud-brand">
                    <a class="room-logo-link room-logo-link-hud" href="/" aria-label="Semyra — início"><img class="room-logo room-logo-hud" src="/assets/images/logo_semyra_symbol.png?v=8b-polish-2" alt=""><strong>Semyra</strong></a>
                    <span class="room-code-compact">Sala <?= e($room['code']) ?></span>
                </div>
                <nav class="room-hud-actions" aria-label="Ações da sala">
                    <button type="button" class="room-icon-button room-participants-button" data-panel-toggle="participants" aria-label="Mostrar participantes" aria-expanded="false"><svg class="room-icon" aria-hidden="true"><use href="#room-icon-users"></use></svg><strong id="room-participant-count"><?= count($participants) ?></strong></button>
                    <button type="button" class="room-icon-button" data-panel-toggle="share" aria-label="Compartilhar sala" aria-expanded="false"><svg class="room-icon" aria-hidden="true"><use href="#room-icon-share"></use></svg></button>
                    <button type="button" class="room-icon-button" data-open-transmission aria-label="Iniciar ou trocar transmissão"><svg class="room-icon" aria-hidden="true"><use href="#room-icon-video-plus"></use></svg></button>
                </nav>
            </header>

            <footer class="room-hud-bottom">
                <div class="room-owner-copy" aria-live="polite">
                    <span data-room-owner><?= $transmission !== null ? e(($transmission['is_owner'] ? 'Você' : $transmission['owner_name']) . ' está transmitindo') : 'Sem transmissão ativa' ?></span>
                    <span class="room-player-status" id="youtube-player-status" role="status" aria-live="polite"></span>
                    <span class="room-player-status" id="livekit-player-status" role="status" aria-live="polite" hidden></span>
                </div>
                <div class="room-player-bar">
                <div class="room-shared-playback" data-shared-playback-controls<?= ($transmission['source'] ?? null) !== 'youtube' ? ' hidden' : '' ?>>
                    <button type="button" class="room-icon-button" data-playback-sync aria-label="Sincronizar reprodução" hidden><svg class="room-icon" aria-hidden="true"><use href="#room-icon-sync"></use></svg></button>
                    <button type="button" class="room-icon-button" data-playback-toggle aria-label="<?= $initialPlaying ? 'Pausar' : 'Reproduzir' ?> transmissão" data-state="<?= $initialPlaying ? 'playing' : 'paused' ?>"><svg class="room-icon" data-icon-play aria-hidden="true"<?= $initialPlaying ? ' hidden' : '' ?>><use href="#room-icon-play"></use></svg><svg class="room-icon" data-icon-pause aria-hidden="true"<?= $initialPlaying ? '' : ' hidden' ?>><use href="#room-icon-pause"></use></svg></button>
                    <label class="room-visually-hidden" for="room-playback-seek">Posição da transmissão</label>
                    <input id="room-playback-seek" data-playback-seek type="range" min="0" max="0" step="1" value="0">
                    <output class="room-playback-time"><span data-playback-current>00:00</span><span data-playback-separator> / </span><span data-playback-duration>00:00</span></output>
                    <button type="button" class="room-live-button" data-playback-live hidden>AO VIVO</button>
                    <span class="room-playback-feedback" data-playback-status role="status" aria-live="polite"></span>
                </div>
                <div class="room-local-controls">
                    <label class="room-visually-hidden" for="room-player-volume">Volume</label>
                    <input id="room-player-volume" class="room-volume-control" data-volume-control type="range" min="0" max="100" step="1" value="100" aria-label="Volume local" aria-valuetext="100%, sem som">
                    <button type="button" class="room-icon-button" data-mute-toggle data-state="muted" aria-label="Ativar som" aria-pressed="true"><svg class="room-icon" data-icon-muted aria-hidden="true"><use href="#room-icon-volume-x"></use></svg><svg class="room-icon" data-icon-audible aria-hidden="true" hidden><use href="#room-icon-volume"></use></svg></button>
                    <button type="button" class="room-icon-button" data-fullscreen-toggle data-state="windowed" aria-label="Entrar em tela cheia"><svg class="room-icon" data-icon-maximize aria-hidden="true"><use href="#room-icon-maximize"></use></svg><svg class="room-icon" data-icon-minimize aria-hidden="true" hidden><use href="#room-icon-minimize"></use></svg></button>
                </div>
                </div>
                <form class="room-end-form" action="/room/<?= e($room['code']) ?>/transmission/end" method="post" data-end-transmission<?= !($transmission['is_owner'] ?? false) ? ' hidden' : '' ?>>
                    <?= $csrfField ?>
                    <input type="hidden" name="transmission_instance_id" value="<?= e($transmission['instance_id'] ?? '') ?>" data-end-transmission-instance-id>
                    <input type="hidden" name="transmission_revision" value="<?= e((string) ($transmission['revision'] ?? '')) ?>" data-end-transmission-revision>
                    <button type="submit" class="room-text-button"><svg class="room-icon" aria-hidden="true"><use href="#room-icon-stop"></use></svg><span>Encerrar transmissão</span></button>
                </form>
            </footer>
        </div>

        <aside class="room-panel room-panel-participants" data-room-panel="participants" hidden aria-labelledby="participants-title">
            <div class="room-panel-heading"><h2 id="participants-title">Na sala</h2><button type="button" class="room-close-button" data-panel-close aria-label="Fechar participantes"><svg class="room-icon" aria-hidden="true"><use href="#room-icon-x"></use></svg></button></div>
            <ul id="room-participant-list" class="room-participant-list">
                <?php foreach ($participants as $participant): ?>
                    <li><span><?= e($participant['name']) ?></span><?php if ($participant['is_you']): ?><strong> (você)</strong><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        </aside>

        <aside class="room-panel room-panel-share" data-room-panel="share" data-room-share data-room-code="<?= e($room['code']) ?>" hidden aria-labelledby="share-title">
            <div class="room-panel-heading"><h2 id="share-title">Compartilhar sala</h2><button type="button" class="room-close-button" data-panel-close aria-label="Fechar compartilhamento"><svg class="room-icon" aria-hidden="true"><use href="#room-icon-x"></use></svg></button></div>
            <label for="room-share-url">Link da sala</label>
            <input id="room-share-url" type="url" readonly>
            <div class="room-panel-actions"><button id="room-copy-link" type="button" class="room-text-button"><svg class="room-icon" aria-hidden="true"><use href="#room-icon-copy"></use></svg><span>Copiar link</span></button><button id="room-native-share" type="button" class="room-text-button" hidden><svg class="room-icon" aria-hidden="true"><use href="#room-icon-share"></use></svg><span>Compartilhar</span></button></div>
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
                <section class="room-media-debug" aria-labelledby="room-media-debug-title">
                    <h3 id="room-media-debug-title">Estado da mídia</h3>
                    <dl id="room-media-debug-state">
                        <div><dt>media_mode</dt><dd data-debug-media-mode>unknown</dd></div>
                        <div><dt>at live edge</dt><dd data-debug-at-live-edge>false</dd></div>
                        <div><dt>transmission revision</dt><dd data-debug-transmission-revision>—</dd></div>
                        <div><dt>playback revision</dt><dd data-debug-playback-revision>—</dd></div>
                        <div><dt>player ready</dt><dd data-debug-player-ready>false</dd></div>
                        <div><dt>player state</dt><dd data-debug-player-state>—</dd></div>
                        <div><dt>local currentTime</dt><dd data-debug-current-time>—</dd></div>
                        <div><dt>duration</dt><dd data-debug-duration>—</dd></div>
                        <div><dt>physical live edge</dt><dd data-debug-live-edge>—</dd></div>
                        <div><dt>live sync delay</dt><dd data-debug-live-sync-delay>—</dd></div>
                        <div><dt>live sync target</dt><dd data-debug-live-sync-target>—</dd></div>
                        <div><dt>official playback</dt><dd data-debug-official-position>—</dd></div>
                        <div><dt>local vs official drift</dt><dd data-debug-local-drift>—</dd></div>
                        <div><dt>behind synchronized live</dt><dd data-debug-behind-live>—</dd></div>
                        <div><dt>UI branch</dt><dd data-debug-ui-branch>preparing</dd></div>
                        <div><dt>wake lock</dt><dd data-debug-wake-lock>—</dd></div>
                    </dl>
                </section>
                <p id="room-presence-status" role="status" aria-live="polite"></p>
            </aside>
        <?php else: ?>
            <p id="room-presence-status" class="room-visually-hidden" role="status" aria-live="polite"></p>
        <?php endif; ?>

        <div class="room-flash-stack" aria-live="polite">
            <?php foreach ($flashes as $type => $messages): ?>
                <?php foreach ($messages as $message): ?>
                    <p class="room-flash room-flash-<?= e($type) ?>" role="status" data-room-flash data-flash-type="<?= e($type) ?>"><?= e($message) ?></p>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <dialog id="room-transmission-dialog" class="room-dialog" aria-labelledby="transmission-dialog-title">
            <form method="post" action="/room/<?= e($room['code']) ?>/transmission">
                <?= $csrfField ?>
                <div class="room-dialog-heading">
                    <div><p class="room-kicker">YouTube</p><h2 id="transmission-dialog-title">Iniciar transmissão</h2></div>
                    <button type="button" class="room-close-button" data-close-transmission aria-label="Cancelar"><svg class="room-icon" aria-hidden="true"><use href="#room-icon-x"></use></svg></button>
                </div>
                <p class="room-replace-warning" data-replace-warning<?= $transmission === null ? ' hidden' : '' ?>><strong data-replace-owner><?= e($transmission['owner_name'] ?? 'Participante') ?></strong> está transmitindo. Iniciar sua transmissão substituirá a transmissão atual.</p>
                <label for="youtube-url">Link do YouTube</label>
                <input id="youtube-url" name="youtube_url" type="url" maxlength="2048" placeholder="https://youtube.com/watch?v=..." required>
                <fieldset class="room-media-mode">
                    <legend>Tipo de conteúdo</legend>
                    <label><input type="radio" name="media_mode" value="vod" checked> <span>Vídeo</span></label>
                    <label><input type="radio" name="media_mode" value="live"> <span>Ao vivo</span></label>
                </fieldset>
                <div class="room-dialog-actions"><button type="button" class="room-secondary-button" data-close-transmission>Cancelar</button><button type="submit" class="room-text-button"><svg class="room-icon" aria-hidden="true"><use href="#room-icon-video-plus"></use></svg><span>Iniciar minha transmissão</span></button></div>
            </form>
        </dialog>
    </main>

    <script src="/assets/js/room-media.js?v=10b21-instance" defer></script>
    <script src="/assets/js/room-player.js?v=10b21-instance" defer></script>
    <script src="/assets/js/room-livekit-player.js?v=10b21-instance" defer></script>
    <script src="/assets/js/room-desktop-host.js?v=11c-host-session" defer></script>
    <script src="/assets/js/room-share.js?v=8b-identity" defer></script>
    <?php if ($debug): ?><script src="/assets/js/room-telemetry.js?v=8b-identity" defer></script><?php endif; ?>
    <script src="/assets/js/room-playback.js?v=10b21-instance" defer></script>
    <script src="/assets/js/room-shell.js?v=10b21-instance" defer></script>
    <script src="/assets/js/room-wake-lock.js?v=8b-identity" defer></script>
    <script src="/assets/js/room-presence.js?v=10b21-instance" defer></script>
<?php endif; ?>
