# Changelog

Todas as mudanças relevantes do Semyra serão documentadas aqui.

## [Unreleased]

### Fixed

- Ingresses externos do bridge agora recebem uma geração por attempt, impedindo cleanup de lease expirada de atingir o recurso de uma lease posterior.
- Comandos, encerramento, observações de live edge e viewers agora são vinculados a um `instance_id` imutável por transmissão, evitando colisões após `end` seguido de novo `start` com revision reutilizada.

### Added

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
