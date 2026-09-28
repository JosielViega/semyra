# Arquitetura

O projeto usa uma arquitetura em camadas pequena. Cada classe deve existir por uma responsabilidade concreta; Services e Models não são obrigatórios para fluxos simples.

```text
Browser
   ↓
public/index.php
   ↓
Router
   ↓
Controller
   ↓
Service (quando houver regra que justifique)
   ↓
Repository
   ↓
PDO / MySQL
```

Sem regra intermediária, o Controller chama o Repository diretamente:

```text
Controller
   ↓
Repository
```

A resposta HTML segue:

```text
Controller
   ↓
View
   ↓
Layout
   ↓
HTML
```

## Responsabilidades

- `public/index.php`: front controller mínimo; inicializa e despacha.
- `bootstrap/app.php`: carrega Composer e ambiente, configura erros, timezone e sessão, e compõe dependências. Não contém negócio.
- `routes/web.php`: relaciona métodos/caminhos a actions e define o fallback 404.
- `app/Core`: infraestrutura reutilizável: Request, Response, Router, View, Session, CSRF, PDO, log e erros.
- `app/Controllers`: coordena cada caso HTTP, sem SQL ou HTML extenso.
- `app/Validation`: valida entradas no backend.
- `app/Repositories`: concentra consultas explícitas de cada domínio e seus prepared statements.
- `app/Services`: coordena regras ou integrações que realmente precisem de uma camada própria.
- `app/Models`: DTOs ou objetos simples quando o domínio os justificar; não é um ORM.
- `resources/views`: apresentação PHP, sempre escapando valores dinâmicos por padrão.
- `config`: arrays de configuração que leem o ambiente.
- `database`: evolução de schema em SQL versionado e seeds opcionais.

## Como evoluir um recurso

1. Defina a rota e o método HTTP.
2. Crie uma action pequena no Controller.
3. Valide dados recebidos e autorização no backend.
4. Adicione um Repository se houver SQL.
5. Adicione um Service apenas para regra ou coordenação significativa.
6. Retorne uma Response ou renderize uma View.
7. Cubra o comportamento fundamental com teste.

Dependências são montadas explicitamente em `bootstrap/app.php` ou `routes/web.php`. Se o projeto crescer muito, um container pode ser avaliado, mas não é necessário no estado atual do Semyra.

## Fluxo de salas

A criação da sala segue:

```text
POST /rooms
    ↓
RoomController
    ↓
RoomCodeGenerator
    ↓
RoomRepository
    ↓
PDO / MySQL
```

O gerador cria códigos públicos aleatórios e o repository tenta inserir cada código; colisões da constraint `UNIQUE` permitem até cinco novas tentativas no controller. A sala nasce vazia e não possui dono.

```text
Room
└── RoomTransmission (0..1)
    ├── source
    ├── owner temporário
    └── revision
```

A primeira fonte suportada é YouTube. A transmissão é iniciada ou substituída por:

```text
POST /room/{code}/transmission
    ↓
RoomTransmissionController
    ↓
YouTubeUrlParser
    ↓
RoomTransmissionRepository
    ↓
MySQL
```

Quem inicia torna-se proprietário da transmissão vigente. Outra pessoa pode substituí-la, incrementando `revision` e assumindo a propriedade. Somente o proprietário atual pode encerrá-la. Não há owner da sala, histórico de transmissões ou expiração automática quando o owner fica offline.

A consulta segue:

```text
GET /room/{code}
    ↓
RoomController
    ↓
RoomRepository
    ↓
room.php
    ↓
room-shell.js
    ↓
room-player.js
    ↓
YouTube IFrame Player API
```

O layout `layouts/room` é fullscreen e independente do layout tradicional da aplicação. O frontend recebe uma apresentação pública escapada da transmissão, sem `room_id`, hash, token ou ID de participante. `room-shell.js` coordena HUD, dialog, painéis, fullscreen e mute locais. `room-player.js` cria, troca ou destrói o player conforme eventos internos de transmissão.

O compartilhamento permanece exclusivamente no frontend e reutiliza a rota pública existente:

```text
room.php
    ↓
room-share.js
    ↓
Clipboard API / Web Share API
```

`room-share.js` recebe somente o código público escapado para compor o título de compartilhamento. A URL é derivada de `window.location` como `/room/{code}`, sem query string ou fragment, e não é armazenada nem enviada a um endpoint próprio. A Clipboard API é opcional, com seleção manual do campo como fallback; a Web Share API é uma melhoria progressiva.

Antes de entrar, a sala renderiza somente o formulário de apelido. O fluxo anônimo é separado por sala na sessão do navegador:

```text
POST /room/{code}/join
    ↓
RoomParticipantController
    ↓
RoomParticipantSession (chave aleatória de 256 bits)
    ↓ SHA-256
RoomParticipantRepository
    ↓
room_participants
```

A chave real nunca é enviada ao frontend nem persistida no banco. O repository grava apenas o hash, o apelido e `last_seen_at`; a constraint composta impede duplicação da mesma identidade na sala.

Depois da entrada, a presença segue um polling simples e sem requisições sobrepostas:

```text
room-presence.js (imediato; ~5 s sem transmissão, ~1 s com transmissão)
    ↓ POST + CSRF
POST /room/{code}/presence
    ↓
touch da identidade + participantes vistos nos últimos 45 s
    ↓
JSON { participants, transmission }
```

A resposta pública não contém IDs internos, hashes, tokens de sessão ou timestamps. Cada participante recebe apenas um `public_id` opaco de 128 bits, derivado com separação de domínio do hash interno para o frontend distinguir identidades com apelidos iguais. `transmission` contém fonte, video ID, revisão, nome do owner, `is_owner`, `media_mode` e playback oficial. Para Live, `live_edge_position_ms` é a borda física projetada, enquanto `live_sync_position_ms` é o target sincronizado derivado e `live_sync_delay_ms` explicita a política atual. O estado `playing` e a âncora da borda são projetados pelo relógio do MySQL. A mesma resposta atualiza o frontend sem polling adicional:

```text
room-presence.js
    ↓ /presence
participants + telemetry + transmission/playback
    ↓ semyra:shared-playback-updated
room-player.js
    ↓ owner ao vivo: getCurrentTime ocasional junto à presença
live_edge_position_ms
```

O owner envia comandos por `POST /room/{code}/transmission/playback`. O update usa compare-and-swap por `room_id`, hash do owner, revisão da transmissão e revisão do playback; sucesso incrementa somente `playback_revision`. A resposta imediata atualiza o owner, enquanto viewers recebem a mesma revisão pelo polling. Falhas de rede preservam o último estado confirmado. Não há `sendBeacon`, WebSocket ou SSE.

```text
room-playback.js
    ↓ snapshot por CustomEvent
room-player.js
    ↓ POST Play/Pause/Seek/AO VIVO
RoomTransmissionController
    ↓ validação + compare-and-swap
RoomTransmissionRepository
    ↓
room_transmissions
```

Ao iniciar YouTube, o participante escolhe explicitamente `vod` ou `live`; não há classificador automático. VOD nasce em `playing`, posição zero, `at_live_edge=false` e revisão um. Live nasce em `playing`, `at_live_edge=true` e revisão um: o player carrega no ponto natural do YouTube, sem aplicar `seekTo(0)`.

Quando uma Live nova ainda não possui âncora, o player natural do owner fornece uma primeira observação de `getCurrentTime()` junto ao polling de presença. O `UPDATE` valida owner, revisões, modo Live, estado playing, borda ativa e exige `live_edge_position_ms IS NULL`; assim a âncora física é inicializada uma única vez e nunca é reancorada pelo player já atrasado. Tentativas moderadas continuam pelo mesmo polling enquanto a âncora for nula. O banco projeta a borda física somando a idade calculada por `CURRENT_TIMESTAMP(3)`; viewers e sessões em DVR nunca escrevem essa âncora. `getDuration()` permanece apenas para VOD e telemetria/diagnóstico.

`RoomTransmissionPlayback::LIVE_SYNC_DELAY_MS` centraliza a margem experimental de 5 segundos. O backend deriva `live_sync_position_ms = max(0, physical edge - delay)`. Quando `at_live_edge=true`, a posição pública oficial é esse target; antes do bootstrap ela permanece desconhecida e o YouTube carrega naturalmente. A transição local `anchorReady=false → true` aplica o target uma vez, sem incluir sua projeção numérica na chave de dispatch e sem seek a cada poll.

Pause captura uma posição fresca e converte a sala em DVR (`at_live_edge=false`). Play e Seek continuam atrás, preservando a posição absoluta projetada. `AO VIVO` publica uma nova revisão em `playing` e `at_live_edge=true`; com âncora pronta, todos fazem seek para o target sincronizado e continuam reproduzindo. A barra Live usa o target sincronizado como máximo, calcula a distância DVR em relação a ele e mantém a margem técnica invisível ao mostrar `🔴 AO VIVO`.

Conceitualmente:

```text
Room
└── active transmission
    ├── source_type
    │   ├── youtube
    │   └── future screen
    └── shared playback
```

Para `source_type=youtube`, o `media_mode` torna-se `vod` ou `live`. YouTube permanece uma fonte permanente. Compartilhamento de tela será uma fonte adicional futura, sem arquitetura técnica definida nesta etapa.

## Telemetria observacional do player

A leitura do player, o transporte e a apresentação permanecem separados:

```text
room-presence.js
    ↓ semyra:player-telemetry-request
room-player.js
    ↓ getPlayerState / getCurrentTime / getDuration
room-presence.js
    ↓ POST /room/{code}/presence
RoomParticipantController
    ↓
RoomPlaybackTelemetry
    ↓
RoomParticipantRepository
    ↓
MySQL
```

A resposta percorre o fluxo independente de apresentação:

```text
JSON
    ↓
room-presence.js
    ↓ semyra:presence-updated
room-telemetry.js
```

`room-player.js` é o único componente com uma referência ao `YT.Player`, mantida dentro da própria IIFE. Ele responde por `CustomEvent` com estado, posição e duração em milissegundos inteiros. `room-presence.js` envia esse snapshot opcional no polling dinâmico; se o player não estiver pronto, a presença continua sem telemetria. `room-telemetry.js` só é carregado em `?debug=1`; o uso normal mantém o diagnóstico invisível. O painel mostra borda física, margem, target sincronizado, posição oficial, posição local e `drift = local - oficial`; o valor só é numérico quando os estados playing/paused são compatíveis.

O banco mantém somente o último snapshot na linha de cada participante e calcula sua idade com o relógio do MySQL. Telemetria é recente por 12 segundos, separadamente da janela de presença de 45 segundos. Para dois participantes no estado `playing`, posições recentes são projetadas pela idade do snapshot e o drift é `posição estimada do outro - posição estimada de você`: positivo significa que o outro está à frente, negativo significa que está atrás. Duração é somente diagnóstica, inclusive em Lives.

Este fluxo de telemetria continua observacional e não define autoridade. O playback oficial é aplicado quando muda a revisão da transmissão, a revisão do playback ou quando a primeira âncora se torna pronta; a projeção seguinte não redispara seek. Não existe seek periódico, eleição, consenso, playback rate, correção contínua de drift ou histórico de amostras. A margem de 5 segundos ainda está em validação empírica. Mute e fullscreen continuam exclusivamente locais.

Como experimento de estabilização, o owner dispõe de `Sincronizar`: em conteúdo playing, o frontend serializa `pause`, confirmação oficial, espera de 2 segundos e `play` — ou `live` quando estava no ponto AO VIVO. Em paused, republica a posição por `seek` e não inicia reprodução. Revisions e ownership são revalidados antes da segunda ação; conflito, substituição ou perda de ownership cancelam a retomada antiga. Viewers continuam reagindo somente às revisions oficiais.

O owner também pode executar esse pulso automaticamente por nova coorte de participantes ainda não cobertos na revision vigente. Cada `public_id` precisa permanecer ativo e com telemetria fresh por quatro segundos; chegadas próximas são agrupadas numa única barreira, e somente os IDs ready capturados no início são marcados após sucesso. Saída observada remove a cobertura para permitir novo warmup no rejoin. Em paused, o estado determinístico estabilizado funciona como barreira natural sem Pause/Play. A cobertura local fica em memória e `sessionStorage`, limitada à revision atual. Posições e drift não entram na decisão nem se tornam autoridade; polling sem nova identidade não repete a barreira.

## Ferramentas de infraestrutura

Os scripts em `bin/`, herdados da base técnica inicial, não fazem parte do fluxo HTTP nem das regras de negócio. `composer setup` prepara uma cópia local conservadoramente. `composer deploy:hostgator` gera, a partir de uma allowlist versionada, um espelho descartável de produção. O espelho nunca se torna uma segunda fonte de código.
