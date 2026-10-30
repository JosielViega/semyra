# Changelog

Todas as mudanças relevantes do Semyra serão documentadas aqui.

## [Unreleased]

### Fixed

- O pacote HostGator agora inclui e exige somente o runner CLI `bin/migrate.php`, mantendo as demais ferramentas de desenvolvimento fora do mirror e permitindo migrations auditáveis a partir do diretório privado.

- Conexões PDO agora fixam a sessão MySQL em UTC, impedindo que o fuso configurado pela hospedagem altere a validade de Host Sessions e a interpretação de timestamps de produção.

- O Ingress WHIP de compartilhamento agora declara tracks como `SCREEN_SHARE`/`SCREEN_SHARE_AUDIO`; o viewer também tolera a normalização LiveKit para `camera`/`microphone` exclusivamente quando a track pertence ao publisher cercado da transmissão vigente, evitando que mídia publicada fique presa em “Aguardando transmissão”.

- O lease da transmissão de tela agora recebe heartbeat desde a aceitação do Start, incluindo as fases de autorização, preparação e conexão; o polling da sala não remove mais a própria transmissão após cinco segundos enquanto GStreamer/WHIP/LiveKit ainda aguardam `PUBLISHING`, e respostas de presence iniciadas antes do Start não desfazem o contexto recém-criado ao chegar atrasadas.
- O compartilhamento de tela agora possui um único emissor inicial e um lifecycle próprio de renovação da Host Session, evitando que a autorização genérica de IPTV revogue o token recém-entregue ao Screen Publisher; respostas `401` distinguem sessão realmente expirada de token inválido/revogado e callbacks antigos não substituem uma renovação mais nova.
- A confirmação do Screen Publisher agora distingue HTTP, autorização, fencing, payload inválido, ingress ausente e estado de erro do LiveKit; `ingress_not_found` e falhas transitórias recebem retry curto e limitado antes do timeout total, sem aceitar `BUFFERING` como sucesso nem remover a exigência de `PUBLISHING` real.
- O bridge Desktop preserva o estado intermediário `connecting` do compartilhamento de tela em vez de convertê-lo em falha e interromper o próprio publisher; erros nativos agora aguardam a drenagem do diagnóstico do GStreamer e chegam à interface como códigos sanitizados específicos.
- Transmissões YouTube e IPTV agora são encerradas quando o owner fica sem instância ativa por 5 segundos; reloads e múltiplas abas sobrevivem pela mesma presença existente, e o `DELETE` é cercado atomicamente por sala, instância, revisão e identidade do owner.
- O fullscreen da sala usa a Fullscreen API no navegador e a janela WPF no Semyra Desktop por capability allowlisted `window.fullscreen`, preservando monitor, bounds e estado maximizado sem reiniciar a mídia.
- O catálogo de publicação IPTV separa poster, título, metadata e CTA em cards responsivos de Filmes/Séries; o seletor horizontal de temporadas permanece visível acima da lista rolável de episódios, sem regredir paginação ou layout de TV.
- Minha IPTV agora separa corretamente os estados visuais, encerra a reprodução local ao sair da página, descarta respostas antigas do catálogo e mantém o refresh grande fora do contexto da UI.
- Configurações IPTV agora bloqueiam operações concorrentes, importam novas fontes automaticamente, destacam a fonte em uso e exibem um loader contextual até o catálogo selecionado estar pronto.
- Ingresses externos do bridge agora recebem uma geração por attempt, impedindo cleanup de lease expirada de atingir o recurso de uma lease posterior.
- Comandos, encerramento, observações de live edge e viewers agora são vinculados a um `instance_id` imutável por transmissão, evitando colisões após `end` seguido de novo `start` com revision reutilizada.

### Added

- Supressão de ruído local e gratuita no microfone do chat de voz com RNNoise 0.2, WebAssembly e AudioWorklet. O modo Aprimorado publica somente a track mono processada no LiveKit, preserva echo cancellation do navegador, evita noise suppression/AGC duplicados e retorna com cleanup ao modo Padrão quando o runtime não é compatível.

- Comunicacao independente da midia nas salas: chat de texto persistente em MySQL com polling incremental, paginacao, idempotencia, rate limit, CSRF e renderizacao segura; e chat de voz WebRTC pelo LiveKit com token curto limitado exclusivamente a microfone, microfone inicialmente desligado, volume/mute recebido independentes e indicador de fala.

- O seletor nativo de compartilhamento gera, somente após seleção, uma pré-visualização JPEG estática e limitada do monitor ou janela, mantida em memória no Desktop e descartada por geração sem backend, disco ou LiveKit.
- Compartilhamento de tela Premium pelo publisher nativo do Semyra Desktop, com seleção opaca de monitor/janela, presets Econômica/Padrão/Alta, áudio de sistema WASAPI opcional, H.264 + Opus por GStreamer/WHIP no LiveKit, Host Session limitada a `screen.publish`, heartbeat cercado por instância/revisão e cleanup automático. O navegador comum permanece viewer e não solicita captura.

- Controles compartilhados reais para o owner de IPTV VOD, com Play/Pause, seek, ±30 s e progresso consultado de um publisher GStreamer persistente e isolado no `Semyra.MediaHost`, cercado por Host Session, instância e revisão e sem URLs em argv, DOM ou respostas.
- Pause de IPTV VOD congela somente a timeline de conteúdo; um pipeline de publicação separado mantém RTP/WHIP monotônico com o último frame e silêncio, evitando drift A/V cumulativo nos receivers. As transições aguardam os estados reais do GStreamer e seek continua republicando com posicionamento preciso.
- A ponte de áudio da publicação VOD agora fixa PCM `S16LE`, 48 kHz, estéreo interleaved nos dois lados do `interaudio` e usa reserva coerente de 200/40/10 ms, eliminando renegociação implícita e saltos de amostra observados no gate técnico sem reiniciar Opus/RTP.
- A transmissão IPTV da sala agora oferece catálogo cinematográfico com TV ao vivo, Filmes e Séries, detalhes lazy de temporadas/episódios e publicação VOD Premium por ID opaco. Filmes MP4/H.264/AAC usam proxy loopback efêmero e pipeline GStreamer/WHIP dedicado, sem expor URL ou credenciais do provider ao JavaScript ou ao backend.
- O gate humano de publicação corrigiu os formulários IPTV independentes do YouTube, scroll/paginação do catálogo, UTF-8 e seleção real de temporadas; VOD preserva pacing pelo GstClock e TV ao vivo mantém o pacing da fonte. Nos dois casos, o vídeo H.264 é normalizado localmente por `x264enc` em constrained-baseline, sem B-frames e com GOP curto antes do WHIP, evitando depender da compatibilidade WebRTC variável do encoder do provider.
- Publicação IPTV productizada na sala do Desktop: a aba distingue conta Free, runtime WHIP indisponível e publicação Premium pronta; o catálogo Live é carregado uma vez por contexto e o launcher local valida PHP, MySQL, MPV, GStreamer e `whipsink` antes do gate manual.
- Entitlement Premium simples por conta, com badge, gate autoritativo e defesa em profundidade para publicação IPTV em sala, sem restringir IPTV local, YouTube ou viewers e sem adicionar billing. A fundação técnica WHIP/LiveKit permanece condicionada às capabilities nativas.

- Progresso VOD local persistente no Desktop, com retomada exata de filmes e episódios, “Continuar assistindo”, estados assistidos e navegação manual anterior/próximo entre temporadas, preservado por refresh Xtream via identidade estável.
- “Continuar assistindo” de séries agora abre a temporada e destaca o episódio correto sem iniciar playback; progresso persistido atualiza o detalhe aberto, e EOF natural avança uma única vez ao próximo episódio preservando fullscreen, inclusive entre temporadas.
- Filmes e séries podem ser removidos de “Continuar assistindo” sem apagar progresso ou episódios assistidos; nova atividade faz o item reaparecer automaticamente.
- Com `--keep-open=yes`, o player observa a transição `eof-reached=false→true` e a converge com `end-file reason=eof` em um único encerramento natural por arquivo; a busca/filtro VOD também ganhou separação consistente da rail e do grid.

- Minha IPTV agora separa TV ao vivo, Filmes e Séries; catálogos Xtream VOD usam busca/categoria/paginação nativas, detalhes lazy de episódios e reprodução segura de filmes/episódios no MPV por ID local, sem expor URLs ou credenciais à WebView.

- Catálogo Xtream VOD local estruturado, com filmes, séries e episódios sob demanda, sincronização global atômica, cache lazy por série e URLs resolvidas somente no native.

- Fonte Xtream nativa no Semyra Desktop, com autenticação e sincronização de categorias/canais Live TV, credenciais protegidas por DPAPI e reprodução pelo MPV existente.
- Reprodução IPTV local exclusivamente por sidecar MPV aquecido e IPC privado, com controles Live/VOD, fullscreen nativo, auto-hide e remoção/limpeza de Recentes isolada por conta e fonte; GStreamer permanece exclusivo da publicação WHIP/LiveKit.
- Favoritos e Recentes da Minha IPTV, gratuitos e persistidos somente no SQLite local por conta e fonte, com identidade lógica resiliente a mudanças de URL da playlist.
- Experiência cinematográfica da Minha IPTV, com player ao vivo integrado, controles próprios, catálogo em grid e configurações secundárias acessíveis.
- Publicação IPTV do Semyra Desktop por WHIP para o LiveKit, autorizada por Host Session e cercada por instância/revisão da transmissão.
- Worker externo capaz de transportar uma fonte MPEG-TS privada para WHIP, com H.264 passthrough, áudio AAC convertido para Opus e supervisão fail-closed por lease; a seleção IPTV ainda não está disponível ao usuário final.
- Fundação do lifecycle externo do bridge IPTV por control plane pull, jobs persistentes, leases com fencing por `instance_id` e gerenciamento de WHIP Ingress, inicialmente validada em dry-run antes do transporte de mídia.
- Viewer browser LiveKit para transmissões `iptv`/`live`, com bundle local lazy-loaded, token subscribe-only vinculado à revisão e filtro do publisher esperado; a criação e seleção IPTV pelo usuário ainda não fazem parte do produto.
- Fundação backend opcional do LiveKit, com configuração segura e emissão de credenciais curtas subscribe-only vinculadas à revisão da transmissão.
- Identidade persistente de contas nas salas, com nickname fixo, participantes lógicos deduplicados e ownership de transmissão durável por usuário autenticado.
- Área autenticada “Minhas salas”, separando salas criadas pelo usuário do histórico de outras salas em que participou.
- Salas temporárias para convidados, com expiração após 24 horas sem atividade, e salas persistentes vinculadas ao usuário que as cria autenticado.
- Contas locais opcionais com cadastro, auto-login, login e logout seguro, sem restringir convidados ou vincular contas às salas nesta etapa.
- Sinal de saída best-effort por instância do player, removendo presença no polling seguinte sem comprometer reloads.
- Identidade efêmera e opaca por instância do player, para que reloads voltem a participar de uma única barreira automática de sincronização.
- Controle local de volume persistido por navegador, com cada nova página iniciando sem som.
- Screen Wake Lock progressivo durante transmissões em reprodução, com liberação em pausa, fim ou página oculta.
- Player 16:9 em modo `contain`, sem corte da imagem em telas retrato, paisagem ou ultrawide.
- Identidade visual da sala com logo oficial e iconografia SVG local, consistente e sem dependências externas.
- Início do projeto Semyra com base na infraestrutura técnica do `modeloPHP`.
- Identidade inicial da aplicação e conceito “Assista junto.”.
- Página inicial e identidade visual base da aplicação.
- Documentação inicial do projeto e do seu estado atual.
- Criação e persistência de salas a partir de URLs suportadas do YouTube.
- Parser local de URLs do YouTube e extração do video ID.
- Geração segura de códigos públicos de sala com tratamento limitado de colisões.
- Página inicial funcional para criar salas e página básica para consultar uma sala.
- Reprodução de vídeo ou Live dentro da sala usando a YouTube IFrame Player API.
- Player responsivo com controles nativos e tratamento básico de erros de incorporação.
- Compartilhamento de salas pela própria URL pública.
- Cópia do link com Clipboard API e fallback por seleção manual.
- Compartilhamento nativo progressivo através da Web Share API.
- Entrada anônima por apelido em cada sala e sessão do navegador.
- Presença de participantes por polling, com atualização a cada 10 segundos e expiração após 45 segundos.
- Persistência segura de participantes usando apenas o hash SHA-256 da chave anônima.
- Telemetria observacional de estado, posição e duração do YouTube Player.
- Medição aproximada de drift entre participantes em reprodução, sem qualquer correção automática.
- Salas independentes da mídia, criadas inicialmente sem transmissão.
- Transmissão ativa separada, com proprietário temporário e revisão incremental.
- Substituição da transmissão por qualquer participante e encerramento restrito ao proprietário atual.
- Layout imersivo fullscreen com HUD temporário e overlays para participantes e compartilhamento.
- Player YouTube sem controles nativos, com mute e fullscreen locais do Semyra.
- Telemetria oculta no uso normal e disponível por `?debug=1`.
- Estado oficial de Play/Pause/Seek por transmissão, controlado somente pelo owner temporário.
- Compare-and-swap por owner, revisão da transmissão e revisão do playback para rejeitar comandos antigos.
- Controles compartilhados imersivos para o owner e aplicação das revisões oficiais nos viewers.
- Polling de presença dinâmico: aproximadamente 1 segundo com transmissão e 5 segundos sem transmissão.
- Seleção explícita de YouTube Vídeo ou Ao vivo ao iniciar uma transmissão, sem classificador heurístico.
- Live iniciada no ponto natural do YouTube, sem `seekTo(0)` e sem usar `getDuration()` como borda.
- Âncora de live edge observada pelo player do owner via `getCurrentTime()`, projetada pelo relógio do banco e transportada no polling já existente.
- DVR de Live com barra relativa ao live edge oficial e ação compartilhada `AO VIVO`.
- Bootstrap único da borda física de Live e target `AO VIVO` sincronizado com margem técnica experimental de 5 segundos.
- Diagnóstico de drift entre a posição local e o playback oficial, sem correção automática.
- Barreira experimental de sincronização com controle manual e pulso automático por nova coorte de participantes prontos em cada revisão da transmissão.

### Removed

- Página, formulário, rota e processamento demonstrativos herdados da base técnica.
