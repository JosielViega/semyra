<section id="room-iptv-publish-modern" class="room-iptv-publish room-iptv-publish-modern" data-iptv-source-panel-modern role="tabpanel" hidden tabindex="0">
    <?php if ($canPublishIptv): ?>
        <div class="room-iptv-publish-controls" data-room-iptv-controls-modern>
            <header class="room-iptv-header">
                <div><span class="room-kicker">IPTV</span><strong>Transmitir para a sala</strong></div>
                <label for="room-iptv-source-modern"><span>Fonte atual</span><select id="room-iptv-source-modern" data-room-iptv-source-modern aria-label="Fonte IPTV"></select></label>
            </header>
            <div class="room-iptv-tabs" role="tablist" aria-label="Catálogo IPTV">
                <button type="button" class="room-iptv-tab is-active" role="tab" aria-selected="true" aria-controls="room-iptv-live-panel" data-iptv-tab="live">TV ao vivo</button>
                <button type="button" class="room-iptv-tab" role="tab" aria-selected="false" aria-controls="room-iptv-movies-panel" data-iptv-tab="movies">Filmes</button>
                <button type="button" class="room-iptv-tab" role="tab" aria-selected="false" aria-controls="room-iptv-series-panel" data-iptv-tab="series">Séries</button>
            </div>
            <section id="room-iptv-live-panel" class="room-iptv-catalog" role="tabpanel" data-iptv-panel="live">
                <form class="room-iptv-toolbar" data-iptv-form="live" role="search"><label><span>Buscar canais</span><input type="search" maxlength="120" data-iptv-query="live" placeholder="Nome do canal"></label><label><span>Grupo</span><select data-iptv-category="live"><option value="">Todos os grupos</option></select></label><button type="submit" class="room-text-button">Buscar</button></form>
                <div class="room-iptv-catalog-scroller" data-iptv-scroller="live" tabindex="0"><div class="room-iptv-grid room-iptv-grid-live" data-iptv-results-modern="live" aria-live="polite" aria-busy="false"></div></div>
                <div class="room-iptv-pagination"><button type="button" data-iptv-previous="live">Anterior</button><span data-iptv-page="live">Página 1</span><button type="button" data-iptv-next="live">Próxima</button></div>
            </section>
            <section id="room-iptv-movies-panel" class="room-iptv-catalog" role="tabpanel" data-iptv-panel="movies" hidden>
                <form class="room-iptv-toolbar" data-iptv-form="movies" role="search"><label><span>Buscar filmes</span><input type="search" maxlength="120" data-iptv-query="movies" placeholder="Título do filme"></label><label><span>Categoria</span><select data-iptv-category="movies"><option value="">Todas as categorias</option></select></label><button type="submit" class="room-text-button">Buscar</button></form>
                <div class="room-iptv-catalog-scroller" data-iptv-scroller="movies" tabindex="0"><div class="room-iptv-grid room-iptv-grid-posters" data-iptv-results-modern="movies" aria-live="polite" aria-busy="false"></div></div>
                <div class="room-iptv-pagination"><button type="button" data-iptv-previous="movies">Anterior</button><span data-iptv-page="movies">Página 1</span><button type="button" data-iptv-next="movies">Próxima</button></div>
            </section>
            <section id="room-iptv-series-panel" class="room-iptv-catalog" role="tabpanel" data-iptv-panel="series" hidden>
                <div data-iptv-series-browser><form class="room-iptv-toolbar" data-iptv-form="series" role="search"><label><span>Buscar séries</span><input type="search" maxlength="120" data-iptv-query="series" placeholder="Título da série"></label><label><span>Categoria</span><select data-iptv-category="series"><option value="">Todas as categorias</option></select></label><button type="submit" class="room-text-button">Buscar</button></form><div class="room-iptv-catalog-scroller" data-iptv-scroller="series" tabindex="0"><div class="room-iptv-grid room-iptv-grid-posters" data-iptv-results-modern="series" aria-live="polite" aria-busy="false"></div></div><div class="room-iptv-pagination"><button type="button" data-iptv-previous="series">Anterior</button><span data-iptv-page="series">Página 1</span><button type="button" data-iptv-next="series">Próxima</button></div></div>
                <article class="room-iptv-series-detail" data-iptv-series-detail hidden></article>
            </section>
        </div>
        <div class="room-runtime-notice" data-room-iptv-unavailable-modern hidden><strong>Publicação IPTV indisponível neste Desktop.</strong><span>O runtime de publicação WHIP não está disponível.</span></div>
        <div class="room-runtime-notice" data-room-vod-unavailable hidden><strong>Publicação sob demanda indisponível.</strong><span>Este Desktop ainda não pode publicar conteúdo sob demanda.</span></div>
        <p class="room-iptv-status" role="status" aria-live="polite" data-room-iptv-status-modern></p>
    <?php else: ?>
        <p class="room-premium-notice" role="status" data-room-iptv-status-modern>Transmitir sua IPTV para a sala é um recurso Premium. Sua IPTV local continua disponível no Semyra Desktop.</p>
    <?php endif; ?>
    <div class="room-iptv-dialog-actions"><button type="button" class="room-secondary-button" data-close-transmission>Cancelar</button></div>
</section>
