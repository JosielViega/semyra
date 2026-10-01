# Spike técnico de IPTV no navegador

> Resultado consolidado e sanitizado das Etapas 10A a 10A.4B. Não registrar credenciais, URLs completas, nomes de streams, tokens ou IDs privados neste documento.

## Objetivo

Registrar evidências do comportamento de uma fonte IPTV autorizada no navegador e no bridge experimental para LiveKit, sem integrar IPTV ao Semyra.

## Resultado consolidado

- A playlist estudada continha 315.248 entradas, todas observadas com `EXTINF` negativo, e apontava para um provider somente HTTP.
- Os samples acessíveis apresentaram CORS funcional; HTTPS no provider não funcionou no teste, mantendo o bloqueio de Mixed Content como gate de uma arquitetura browser direta.
- A fonte Live compatível entregou MPEG-TS contínuo com H.264/AAC. `mpegts.js` foi viável para essa fonte, mas a compatibilidade variou por stream e HEVC não pode ser generalizado.
- No VOD observado, o browser reportou duração `Infinity`; o mpv apresentou duração crescente, não total, `seekable=false`, sem HTTP Range e sem comprovar sincronização VOD completa.

## Ambiente

- Data:
- Navegador e versão:
- Sistema operacional:
- Protocolo da página local:
- Versão do hls.js: 1.7.3

## Fonte testada

- Tipo: Xtream-compatible / M3U / URL direta
- Protocolo do provider: HTTP / HTTPS / desconhecido
- Observação sanitizada:

## Xtream/M3U

- Autenticação Xtream:
- CORS da API/catálogo:
- Playlist M3U remota/local:
- Fetch M3U remoto: success / fail / not-tested
- Quantidade de itens analisados:
- Protocolo M3U remoto: HTTP / HTTPS / n/a
- Credenciais detectadas na URL da playlist: sim / não / desconhecido
- Formatos anunciados:

## CORS

- API:
- Manifest HLS:
- Segmentos de mídia:
- Evidência sanitizada:

## Protocolos HTTP/HTTPS

- HTTPS anunciado:
- Mixed Content prospectivo:
- Resultado:

## Live

- Formato:
- Tipo de mídia detectado:
- Método de playback:
- Protocolo do recurso:
- Reprodução:
- Estágio de falha:
- HLS manifest:
- HLS segmentos:
- HLS fatal/type/details sanitizados:
- MediaError.code:
- Resolução:
- Áudio:
- Erro sanitizado:

## VOD

- Container/extensão:
- Tipo de mídia detectado:
- Método de playback:
- Protocolo do recurso:
- Reprodução:
- Estágio de falha:
- MediaError.code:
- Duração:
- Áudio:
- Erro sanitizado:

## Containers/codecs

- Containers observados:
- Codecs observados pelo navegador:
- Compatibilidades/incompatibilidades:

## DVR/seek

- Janela seekable:
- Seek Live/DVR:
- Seek VOD:

## Credenciais

- Credenciais aparecem na URL da playlist: sim / não / desconhecido
- Credenciais aparecem na URL da mídia: sim / não / desconhecido
- Exposição observada no DevTools:
- Nenhum segredo registrado neste documento: confirmar

## Compatibilidade Chrome

- Resultado:
- Limitações:

## Compatibilidade Edge

- Resultado:
- Limitações:

## Riscos

- CORS:
- Mixed Content:
- Risco prospectivo para produção HTTPS: risk / no-obvious-risk / unknown
- Exposição de credenciais:
- Containers/codecs:
- Outros:

## Conclusões provisórias

Preencher somente depois do teste real. Não inferir compatibilidade a partir do teste HLS público.

## MPEGTS.JS PLAYBACK REPORT

- Biblioteca: mpegts.js 1.8.2, bundle local Apache-2.0
- Modo: LOCAL HTTP TEST
- Feature detection (`isSupported`, MSE Live, MSE H265 e loader):
- `LIVE_H264_1`: playback, codecs, resolução, áudio, estabilidade por 60s, buffer, pause/resume, reconnect e seek/DVR
- `LIVE_H264_2`: playback, codecs, resolução, áudio e estabilidade por 30s
- `LIVE_H265_1`: feature H265, codec real e playback
- `VOD_1`: playback, duração, pause/resume, seek +30s, seek aleatório e HTTP Range
- CORS observado pelo browser:
- Confirmação humana de imagem/áudio:

Hipótese em teste: o provider entrega MPEG-TS contínuo por HTTP e o mpegts.js pode transmuxar formatos compatíveis para MSE. Um sucesso local não elimina o bloqueio de Mixed Content em uma futura página HTTPS. A ausência de `Content-Length`/`Accept-Ranges` pode limitar duração e seek de VOD.

Resultados técnicos sanitizados observados no Chrome 150:

- primeiro Live: AVC/AAC 1920×1080, reprodução técnica e estabilidade de 60 segundos aprovadas, pause/resume e reconexão aprovados;
- segundo Live: `MediaError/FormatUnsupported`, confirmando compatibilidade não uniforme;
- candidato HEVC: codec real `hvc1.1.1.L120.B0`, AAC, 1920×1080 e reprodução técnica aprovada;
- VOD MPEG-TS: reprodução e pause/resume aprovados, mas duração desconhecida, HTTP Range indisponível e seeks indisponíveis;
- seek Live funcionou dentro da janela local do buffer MSE; DVR do provider não foi confirmado;
- imagem visível e áudio audível foram confirmados humanamente no fluxo Live compatível;
- provider somente HTTP e falha de HTTPS mantêm risco bloqueante de Mixed Content em produção.

## VOD duration/seek parity with ynoTV/mpv

Referência comportamental confirmada no projeto público do ynoTV, sem reutilização de código AGPL:

- runtime baseado em mpv/libmpv;
- status periódico inclui propriedades equivalentes a `time-pos` e `duration`;
- seek absoluto é delegado ao comando mpv;
- alguns fluxos podem obter duração também pela metadata do catálogo/provider.

As fontes de duração permanecem separadas:

- playlist EXTINF: negativa para `VOD_1`; agregado com 315.248 negativas e nenhuma positiva, zero ou ausente;
- catálogo/provider: não testado nesta fonte M3U;
- mpv runtime: testado a partir do sidecar da instalação manual oficial do ynoTV v2.5.6, carregando `VOD_1` somente por IPC privado;
- browser MediaSource: `Infinity`.

O sidecar mpv reconheceu MPEG-TS, H.264/AAC e 1920×1080. A duração apareceu como finita desde o carregamento, porém cresceu de 2,112 s para 47,634 s durante os primeiros 30 s e não representou uma duração total estável. `seekable` e `partially-seekable` foram falsos, e o seek absoluto +30 falhou após aproximadamente 12,1 s. Playback e pause/resume funcionaram na execução principal, mas uma repetição imediata ficou estagnada, portanto o comportamento não foi reprodutível. A diferença em relação a um fluxo VOD completo do ynoTV pode depender de metadata de catálogo/provider; nenhuma solução web é proposta nesta etapa e o mecanismo de seek permanece desconhecido.

## Gates pendentes para 10B

- local de execução, lifecycle e startup automático do bridge;
- autenticação bridge↔Semyra e política de autorização IPTV;
- UX de seleção/troca de canal e transmissões simultâneas;
- recuperação de falhas, quotas, billing e proteção contra abuso;
- formatos/navegadores suportados e eventual escopo de VOD.

## Resumo cruzado: bridge LiveKit WHIP

A Etapa 10A.4B validou localmente o caminho continuous MPEG-TS → GStreamer → WHIP → LiveKit → dois browsers, sem integrar IPTV à aplicação. URL Input não foi usado: raw MPEG-TS contínuo não consta entre os formatos HTTP documentados e a URL privada não deve ser entregue ao serviço.

O feeder local resolveu somente o candidato autorizado em memória e enviou bytes pelo stdin do container oficial. O vídeo H.264 passou sem decode/reencode; o áudio AAC foi convertido localmente para Opus. O Ingress LiveKit permaneceu sem transcoding. Dois viewers subscribe-only receberam H.264/Opus em 1920×1080/30 fps; imagem e áudio foram confirmados humanamente em ambos, com o mesmo canal e sincronismo aparente bom.

```text
Provider HTTP MPEG-TS
    ↓
bridge local/privado (H.264 passthrough; AAC → Opus)
    ↓ WHIP
LiveKit Cloud
    ↓ WebRTC
viewers
```

As credenciais do provider chegam somente ao bridge. O LiveKit recebe a mídia processada, e o viewer recebe apenas a mídia WebRTC.

Credenciais, host, URL, playlist, endpoint WHIP e chaves permaneceram fora de arquivos versionáveis, documentação, relatório e saída compartilhável. O resultado detalhado e sanitizado está em `tests/manual/livekit-spike/IPTV_WHIP_REPORT.md`. Isso prova a arquitetura experimental, não autoriza integração 10B nem relay na HostGator.
