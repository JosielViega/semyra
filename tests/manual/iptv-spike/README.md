# Laboratório IPTV no navegador

Laboratório local e experimental da Etapa 10A. Ele mede a compatibilidade real do navegador com fontes autorizadas sem integrar IPTV às salas ou ao backend do Semyra.

## Dependência local

- Projeto: [hls.js](https://github.com/video-dev/hls.js)
- Versão: `1.7.3`
- Licença: Apache-2.0
- Origem: pacote oficial `hls.js@1.7.3`, obtido com `npm pack` do registro npm
- Bundle: `vendor/hls.min.js`
- Licença: `vendor/LICENSE-hls.js.txt`

### MPEG-TS contínuo

- Projeto: [mpegts.js](https://github.com/xqq/mpegts.js)
- Versão: `1.8.2`
- Licença: Apache-2.0
- Origem: pacote oficial `mpegts.js@1.8.2`, obtido com `npm pack` do registro npm
- Bundle de distribuição: `vendor/mpegts.js`
- Licença local: `vendor/LICENSE-mpegts.js.txt`
- SHA-256: `BDA31748736A69CB610C2EDF4623E633F1F4F47B5BDA83668C8D287E51B0C3A8`

Não existe dependência npm permanente, `package.json`, CDN ou código remoto carregado pela página.

Os bundles e licenças em `vendor/` são artefatos locais gerados e não são versionados. Para prepará-los novamente, execute na raiz do repositório:

```powershell
powershell -ExecutionPolicy Bypass -File tests/manual/prepare-browser-dependencies.ps1
```

O script baixa somente as versões fixadas acima, confere o SHA-256 dos bundles e não cria `package.json`, `package-lock.json` ou `node_modules` permanentes.

## Como executar

Na raiz do projeto:

```powershell
php tests/manual/iptv-spike/analyze-local-playlist.php
php -S 127.0.0.1:8016 -t tests/manual/iptv-spike
```

Abra `http://127.0.0.1:8016/` no Chrome ou Edge. Se a porta `8016` estiver ocupada, escolha outra porta livre e ajuste a URL.

Os modos disponíveis são:

- Xtream-compatible: testa `player_api.php`, catálogo mínimo Live/VOD e URLs diretas de mídia;
- M3U: aceita URL remota, arquivo local ou texto colado;
- URL de mídia direta: testa HLS, MP4/VOD, MPEG-TS bruto ou autodetecção.
- MPEG-TS real: usa IDs efêmeros para testar candidatos confirmados pelo analisador com `mpegts.js`.

O analisador grava as URLs reais somente em `.iptv-spike-candidates.json`, fora do docroot e protegido por `.git/info/exclude`. `candidate.php` aceita apenas acesso loopback, desabilita cache e entrega uma URL por ID efêmero. Isso não é proxy: a mídia flui diretamente do navegador para o provider.

Para a validação manual, selecione **MPEG-TS real** e teste nesta ordem:

1. `LIVE_H264_1`: confirme imagem e áudio e mantenha a reprodução por pelo menos 60 segundos;
2. use **Pausar 5s / retomar** e depois **Reconectar**;
3. teste `LIVE_H264_2` por aproximadamente 30 segundos;
4. teste `LIVE_H265_1` somente se `mseH265Playback` estiver habilitado;
5. teste `VOD_1`, pause/retome e use **Seek +30s** e **Seek para metade**.

Copie apenas o bloco **MPEGTS.JS PLAYBACK REPORT**. Imagem visível e áudio audível exigem confirmação humana; eventos automáticos não substituem essa verificação.

## Playlist privada local

O arquivo autorizado `playlist_venlomxo1402_plus.m3u`, quando presente na raiz do repositório, deve permanecer protegido apenas em `.git/info/exclude`. Ele pode ser selecionado no campo **Arquivo local .m3u/.m3u8**: a página usa `FileReader` e mantém o conteúdo somente na memória do navegador, sem criar uma rota HTTP para o arquivo.

A análise agregada e sanitizada pode ser repetida, na raiz do projeto, com:

```powershell
php tests/manual/iptv-spike/analyze-local-playlist.php
```

O comando lê internamente apenas esse caminho local, limita fortemente as sondagens de rede, não recebe URLs pela linha de comando e grava `local-playlist-report.txt` sem host, credenciais, tokens, IDs ou URLs brutas. Nunca compartilhe a playlist original.

## Segurança e privacidade

Credenciais digitadas permanecem somente na memória da página atual. O laboratório não usa banco, backend, cookies, `localStorage`, `sessionStorage` ou IndexedDB. Não inclua credenciais em query strings da página local e não envie dados IPTV ao Codex ou ao ChatGPT.

Depois de uma playlist M3U remota ser carregada, sua URL é removida do campo. O teste Xtream também limpa servidor, username e password após a tentativa, mantendo em memória apenas o contexto necessário quando a autenticação funciona. URLs diretas detectadas com token/credencial são removidas do campo ao iniciar o teste. Não envie screenshots contendo os campos antes dessa limpeza.

Na reprodução direta Xtream, o próprio navegador/provedor recebe URLs que podem conter as credenciais. Mascarar a interface não as esconde do DevTools, do histórico de rede do navegador ou do provedor.

Use **Copiar relatório sanitizado** para compartilhar somente o diagnóstico allowlisted. O relatório omite host, credenciais, URLs, nomes e IDs de streams. Use **Limpar dados** para destruir o player, remover o objeto HLS, limpar inputs e descartar as referências mantidas pelo laboratório.

O relatório diferencia credenciais na playlist e na mídia, protocolo, tipo detectado, método de playback e estágio de falha. Uma falha de API não prova CORS isoladamente, e uma API acessível não prova que manifest e segmentos de mídia possuem CORS suficiente.

## Testes puros

```powershell
node --test tests/manual/iptv-spike/iptv-spike.test.cjs
node --test tests/manual/iptv-spike/mpegts-spike.test.cjs
node --check tests/manual/iptv-spike/iptv-spike.js
node --check tests/manual/iptv-spike/mpegts-spike.js
```

## Paridade de duração/seek VOD com ynoTV/mpv

O ynoTV é usado somente como referência comportamental: sua arquitetura pública usa mpv/libmpv, consulta `time-pos` e `duration`, delega seek absoluto ao mpv e, em alguns fluxos, também dispõe de duração vinda do catálogo do provider. Nenhum código do ynoTV foi copiado.

O diagnóstico mantém separadas quatro fontes:

- duração `EXTINF` da playlist;
- duração de metadata do catálogo/provider;
- duração runtime do mpv;
- `video.duration` do pipeline browser/MediaSource.

Para o `VOD_1`, o EXTINF é negativo, o catálogo não foi testado e o navegador informou `Infinity`. Após a instalação manual oficial do ynoTV v2.5.6, o sidecar `mpv.exe` foi testado sem alterar a instalação. O script `probe-mpv-vod.ps1` inicia o runtime ocioso e envia a URL somente em memória por um named pipe aleatório; a URL não entra nos argumentos do processo nem nos relatórios.

O mpv reconheceu MPEG-TS com H.264/AAC em 1920×1080. Em uma execução, o playback avançou e pause/resume funcionou, mas a duração finita cresceu de 2,112 s no carregamento para 47,634 s aos 30 s, sem se comportar como duração total estável. `seekable` e `partially-seekable` permaneceram falsos e o seek absoluto +30 falhou. Uma repetição imediata carregou o mesmo formato, mas ficou estagnada, registrando falta de reprodutibilidade. O resultado sanitizado está em `mpv-vod-parity-report.txt`.

## Limitações

- Falhas de `fetch` no navegador podem ser rede, TLS, DNS ou CORS; a mensagem é classificada como possibilidade, não como prova isolada.
- `canPlayType()` é apenas um sinal anunciado pelo navegador, não garantia de reprodução.
- API CORS e mídia CORS são medidos separadamente.
- MPEG-TS contínuo pode falhar mesmo quando segmentos TS dentro de HLS funcionam.
- O laboratório não implementa proxy, DRM, transcoding, FFmpeg, Stalker/MAG, EPG, séries, sincronização ou workaround de Mixed Content.
- O teste HLS público comprova apenas o funcionamento do laboratório; não comprova compatibilidade com uma fonte privada.

## Encerramento e resultado do spike

Ao terminar, use **Limpar dados**, feche o servidor PHP local e confirme que nenhuma URL privada permaneceu em arquivos ou argumentos de processo. A playlist, o mapa de candidatos, o runtime privado do ynoTV e `vendor/` devem continuar somente no ambiente local.

O spike comprovou a leitura sanitizada da playlist, HLS no navegador quando a fonte é compatível, MPEG-TS H.264/AAC com `mpegts.js` e as limitações de duração/seek do VOD observado. Ele não comprovou compatibilidade universal entre providers, H.265 no navegador, DRM, proxy seguro, catálogo completo nem integração IPTV com as salas do Semyra.
