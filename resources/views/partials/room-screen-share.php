<section class="room-screen-share" data-screen-source-panel role="tabpanel" hidden tabindex="0">
    <?php if ($canShareScreen): ?>
        <div class="room-screen-share-copy">
            <span class="room-kicker">Tela</span>
            <h3>Compartilhar tela</h3>
            <p>Compartilhe um monitor ou uma janela com todos na sala.</p>
        </div>
        <div class="room-screen-targets" data-screen-targets aria-label="Telas e janelas disponíveis"></div>
        <section class="room-screen-preview" aria-labelledby="room-screen-preview-title">
            <div class="room-screen-preview-heading">
                <strong id="room-screen-preview-title">Pré-visualização</strong>
                <span data-screen-preview-meta></span>
            </div>
            <div class="room-screen-preview-frame" data-screen-preview-frame data-state="empty">
                <img data-screen-preview-image alt="Pré-visualização do item selecionado" hidden>
                <p data-screen-preview-placeholder>Selecione um monitor ou uma janela.</p>
            </div>
        </section>
        <div class="room-screen-options">
            <label class="room-screen-quality-option" for="room-screen-share-quality">
                <strong>Qualidade</strong>
                <select id="room-screen-share-quality" data-screen-share-quality>
                    <option value="economy">Econômica (720p · 15 fps)</option>
                    <option value="standard" selected>Padrão (720p · 30 fps)</option>
                    <option value="high">Alta (1080p · 30 fps)</option>
                </select>
            </label>
            <label class="room-screen-audio-option">
                <span class="room-screen-audio-label">Áudio do sistema</span>
                <span class="room-screen-switch"><input type="checkbox" data-screen-share-audio checked><span aria-hidden="true"></span></span>
            </label>
        </div>
        <p class="room-screen-audio-help">A captura é feita localmente pelo Semyra Desktop. Nenhum identificador nativo é enviado à página.</p>
        <p class="room-screen-share-status" data-screen-share-status role="status" aria-live="polite"></p>
        <div class="room-dialog-actions"><button type="button" class="room-secondary-button" data-close-transmission>Cancelar</button><button type="button" class="room-text-button" data-screen-share-start>Compartilhar</button></div>
    <?php else: ?>
        <p class="room-premium-notice" role="status">Compartilhamento de tela é um recurso Premium.</p>
        <div class="room-dialog-actions"><button type="button" class="room-secondary-button" data-close-transmission>Cancelar</button></div>
    <?php endif; ?>
</section>
