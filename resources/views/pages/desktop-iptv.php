<section class="iptv-catalog" aria-labelledby="iptv-title" data-iptv-catalog>
    <header class="iptv-catalog-header">
        <div>
            <p class="eyebrow">Semyra Desktop</p>
            <h1 id="iptv-title">Minha IPTV</h1>
            <p>Seu catálogo IPTV fica protegido neste dispositivo, sem enviar credenciais ou endereços ao site.</p>
        </div>
    </header>

    <div class="iptv-browser-notice" data-iptv-browser-notice role="status">
        Este recurso está disponível no Semyra Desktop.
    </div>

    <div class="iptv-desktop-panel" data-iptv-desktop-panel hidden>
        <section class="iptv-source-panel" aria-labelledby="iptv-sources-title">
            <div class="iptv-section-heading">
                <h2 id="iptv-sources-title">Suas fontes</h2>
                <button type="button" class="button-secondary" data-iptv-pick-file>Adicionar arquivo M3U</button>
            </div>

            <form class="iptv-url-form" data-iptv-url-form>
                <label>Nome <input name="name" maxlength="100" required autocomplete="off"></label>
                <label>URL M3U <input name="location" type="url" maxlength="4096" required autocomplete="off" placeholder="https://…"></label>
                <button type="submit">Adicionar URL</button>
            </form>

            <p class="iptv-feedback" data-iptv-feedback role="status" aria-live="polite"></p>
            <div class="iptv-source-list" data-iptv-source-list></div>
        </section>

        <section class="iptv-channel-panel" aria-labelledby="iptv-channels-title">
            <div class="iptv-section-heading">
                <h2 id="iptv-channels-title">Catálogo</h2>
            </div>
            <form class="iptv-search-form" data-iptv-search-form>
                <label>Buscar <input name="query" maxlength="120" autocomplete="off"></label>
                <label>Grupo <select name="group"><option value="">Todos os grupos</option></select></label>
                <button type="submit">Buscar</button>
            </form>
            <div class="iptv-channel-list" data-iptv-channel-list></div>
            <div class="iptv-pagination">
                <button type="button" class="button-secondary" data-iptv-previous disabled>Anterior</button>
                <button type="button" class="button-secondary" data-iptv-next disabled>Próxima</button>
            </div>
            <aside class="iptv-media-panel" data-iptv-media-panel aria-labelledby="iptv-media-title">
                <div>
                    <h3 id="iptv-media-title">Teste local do canal</h3>
                    <p data-iptv-media-status role="status" aria-live="polite">Runtime de mídia indisponível.</p>
                </div>
                <button type="button" class="button-secondary" data-iptv-media-stop disabled>Parar teste</button>
            </aside>
        </section>
    </div>
</section>
<script src="/assets/js/desktop-iptv.js?v=11g-account-isolation" defer></script>
