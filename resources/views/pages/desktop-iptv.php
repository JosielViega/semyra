<section class="iptv-experience" aria-labelledby="iptv-title" data-iptv-catalog>
    <svg class="iptv-icon-sprite" aria-hidden="true">
        <symbol id="iptv-icon-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.5"></circle><path d="m16 16 4 4"></path></symbol>
        <symbol id="iptv-icon-settings" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.6v-.2h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"></path></symbol>
        <symbol id="iptv-icon-volume" viewBox="0 0 24 24"><path d="M4 9h4l5-4v14l-5-4H4z"></path><path d="M16 9a4 4 0 0 1 0 6M18.5 6.5a8 8 0 0 1 0 11"></path></symbol>
        <symbol id="iptv-icon-muted" viewBox="0 0 24 24"><path d="M4 9h4l5-4v14l-5-4H4zM17 9l5 5M22 9l-5 5"></path></symbol>
        <symbol id="iptv-icon-fullscreen" viewBox="0 0 24 24"><path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5"></path></symbol>
        <symbol id="iptv-icon-compress" viewBox="0 0 24 24"><path d="M8 8H3V3M16 8h5V3M8 16H3v5M16 16h5v5"></path></symbol>
        <symbol id="iptv-icon-stop" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2"></rect></symbol>
        <symbol id="iptv-icon-tv" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13" rx="3"></rect><path d="m8 3 4 3 4-3"></path></symbol>
        <symbol id="iptv-icon-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"></path></symbol>
        <symbol id="iptv-icon-close" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"></path></symbol>
    </svg>

    <header class="iptv-topbar">
        <div><p class="eyebrow">Semyra Desktop</p><h1 id="iptv-title">Minha IPTV</h1></div>
        <div class="iptv-topbar-actions">
            <div class="iptv-source-switcher" data-iptv-source-switcher hidden>
                <span class="iptv-source-caption">Fonte</span>
                <strong data-iptv-current-source></strong>
                <label class="iptv-source-select-wrap" data-iptv-source-select-wrap hidden>
                    <span class="sr-only">Selecionar fonte IPTV</span>
                    <select data-iptv-source-select aria-label="Selecionar fonte IPTV"></select>
                </label>
            </div>
            <button type="button" class="iptv-settings-trigger" data-iptv-settings-open aria-haspopup="dialog" aria-controls="iptv-settings" aria-expanded="false">
                <svg aria-hidden="true"><use href="#iptv-icon-settings"></use></svg>Configurações IPTV
            </button>
        </div>
    </header>

    <div class="iptv-browser-notice" data-iptv-browser-notice role="status" hidden>Abra esta página no Semyra Desktop para acessar seu catálogo local.</div>

    <div class="iptv-desktop-panel" data-iptv-desktop-panel>
        <section class="iptv-source-loading" data-iptv-source-loading aria-live="polite" aria-busy="true">
            <span class="iptv-player-spinner" aria-hidden="true"></span><p>Carregando sua IPTV…</p>
        </section>
        <section class="iptv-onboarding" data-iptv-onboarding hidden aria-labelledby="iptv-onboarding-title">
            <div class="iptv-onboarding-symbol" aria-hidden="true"><svg><use href="#iptv-icon-tv"></use></svg></div>
            <p class="eyebrow">Sua programação, do seu jeito</p>
            <h2 id="iptv-onboarding-title">Adicione sua IPTV para começar.</h2>
            <p>Seu catálogo e suas credenciais permanecem protegidos neste dispositivo.</p>
            <button type="button" data-iptv-onboarding-open><svg aria-hidden="true"><use href="#iptv-icon-plus"></use></svg>Adicionar IPTV</button>
        </section>

        <div class="iptv-library" data-iptv-library hidden>
            <section class="iptv-player-stage" data-iptv-player-stage data-state="idle" aria-label="Player IPTV local">
                <video data-iptv-video playsinline preload="none" aria-label="Canal IPTV em reprodução"></video>
                <div class="iptv-player-ambient" aria-hidden="true"></div>
                <div class="iptv-player-empty" data-iptv-player-empty>
                    <svg aria-hidden="true"><use href="#iptv-icon-tv"></use></svg>
                    <h2>Escolha um canal para assistir</h2><p>Explore seu catálogo logo abaixo.</p>
                </div>
                <div class="iptv-player-state" data-iptv-player-state hidden><span class="iptv-player-spinner" aria-hidden="true"></span><p data-iptv-player-state-label>Preparando canal…</p></div>
                <div class="iptv-player-error" data-iptv-player-error hidden>
                    <svg aria-hidden="true"><use href="#iptv-icon-tv"></use></svg>
                    <h2 data-iptv-error-title>Não foi possível abrir este canal.</h2><p data-iptv-error-copy>Tente novamente ou escolha outro canal do catálogo.</p>
                    <div><button type="button" data-iptv-retry>Tentar novamente</button><button type="button" class="iptv-button-quiet" data-iptv-choose-channel>Escolher outro canal</button></div>
                </div>
                <button type="button" class="iptv-autoplay-action" data-iptv-autoplay hidden>Clique para reproduzir</button>
                <div class="iptv-player-chrome" data-iptv-player-chrome>
                    <div class="iptv-player-now">
                        <span class="iptv-live-badge" data-iptv-live-badge hidden><i aria-hidden="true"></i>Ao vivo</span>
                        <strong data-iptv-current-channel>Minha IPTV</strong>
                        <span class="iptv-player-status" data-iptv-media-status role="status" aria-live="polite">Escolha um canal</span>
                    </div>
                    <div class="iptv-player-controls" data-iptv-player-controls>
                        <button type="button" class="iptv-icon-button" data-iptv-mute aria-label="Silenciar" title="Silenciar"><svg data-iptv-volume-icon aria-hidden="true"><use href="#iptv-icon-volume"></use></svg><svg data-iptv-muted-icon aria-hidden="true" hidden><use href="#iptv-icon-muted"></use></svg></button>
                        <label class="iptv-volume-control"><span class="sr-only">Volume</span><input type="range" min="0" max="100" value="80" data-iptv-volume aria-label="Volume"></label>
                        <button type="button" class="iptv-icon-button" data-iptv-fullscreen aria-label="Entrar em tela cheia" title="Entrar em tela cheia"><svg data-iptv-fullscreen-icon aria-hidden="true"><use href="#iptv-icon-fullscreen"></use></svg><svg data-iptv-compress-icon aria-hidden="true" hidden><use href="#iptv-icon-compress"></use></svg></button>
                        <button type="button" class="iptv-stop-button" data-iptv-media-stop disabled><svg aria-hidden="true"><use href="#iptv-icon-stop"></use></svg>Parar</button>
                    </div>
                </div>
            </section>

            <section class="iptv-content" aria-labelledby="iptv-channels-title">
                <div class="iptv-content-heading"><div><p class="eyebrow">Canais</p><h2 id="iptv-channels-title">O que você quer assistir?</h2></div><span data-iptv-result-count aria-live="polite"></span></div>
                <form class="iptv-toolbar" data-iptv-search-form role="search">
                    <label class="iptv-search-field"><span class="sr-only">Buscar canais</span><svg aria-hidden="true"><use href="#iptv-icon-search"></use></svg><input name="query" type="search" maxlength="120" autocomplete="off" placeholder="Buscar canais"></label>
                    <label class="iptv-group-field"><span class="sr-only">Filtrar por grupo</span><select name="group" aria-label="Filtrar por grupo"><option value="">Todos os grupos</option></select></label>
                    <button type="submit">Buscar</button>
                </form>
                <div class="iptv-channel-grid" data-iptv-channel-list aria-live="polite" aria-busy="false"></div>
                <div class="iptv-pagination" aria-label="Paginação do catálogo"><button type="button" class="iptv-button-quiet" data-iptv-previous disabled>Anterior</button><span data-iptv-page-label>Página 1</span><button type="button" class="iptv-button-quiet" data-iptv-next disabled>Próxima</button></div>
            </section>
        </div>
    </div>

    <dialog class="iptv-settings" id="iptv-settings" data-iptv-settings data-settings-state="idle" aria-labelledby="iptv-settings-title" aria-busy="false">
        <div class="iptv-settings-header"><div><p class="eyebrow">Neste dispositivo</p><h2 id="iptv-settings-title">Configurações IPTV</h2></div><button type="button" class="iptv-icon-button" data-iptv-settings-close aria-label="Fechar configurações" title="Fechar"><svg aria-hidden="true"><use href="#iptv-icon-close"></use></svg></button></div>
        <div data-iptv-settings-content>
            <p class="iptv-settings-privacy">Fontes, endereços e credenciais permanecem protegidos localmente e não são exibidos nesta tela.</p>
            <section class="iptv-settings-add" aria-labelledby="iptv-add-source-title">
                <div class="iptv-settings-section-heading"><h3 id="iptv-add-source-title">Adicionar fonte</h3><button type="button" class="iptv-button-quiet" data-iptv-pick-file><svg aria-hidden="true"><use href="#iptv-icon-plus"></use></svg>Adicionar arquivo M3U</button></div>
                <form class="iptv-url-form" data-iptv-url-form><label>Nome <input name="name" maxlength="100" required autocomplete="off" placeholder="Minha lista"></label><label>URL M3U <input name="location" type="url" maxlength="4096" required autocomplete="off" placeholder="https://…"></label><button type="submit" data-iptv-add-url>Adicionar URL</button></form>
            </section>
            <section class="iptv-settings-sources" aria-labelledby="iptv-sources-title"><div class="iptv-settings-section-heading"><h3 id="iptv-sources-title">Fontes cadastradas</h3></div><p class="iptv-feedback" data-iptv-feedback role="status" aria-live="polite"></p><div class="iptv-source-list" data-iptv-source-list></div></section>
        </div>
        <div class="iptv-settings-loader" data-iptv-settings-loader role="status" aria-live="polite" hidden>
            <span class="iptv-settings-loader-mark" aria-hidden="true"><span></span><span></span><span></span></span>
            <strong data-iptv-settings-loader-title>Carregando sua IPTV…</strong>
            <p data-iptv-settings-loader-copy>Aguarde enquanto preparamos seu catálogo.</p>
        </div>
    </dialog>
</section>
<script src="/assets/vendor/hls/hls.min.js?v=1.7.3" defer></script>
<script src="/assets/js/desktop-iptv.js?v=11h-b-settings" defer></script>
