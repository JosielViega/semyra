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

## Planos de controle e mídia LiveKit

A fundação LiveKit separa responsabilidades sem mudar a fonte YouTube existente:

```text
Semyra / HostGator
    control plane: sala, participação, transmissão e autorização de token
            ↓ JWT curto subscribe-only
LiveKit
    realtime media plane
            ↑ WHIP (etapa futura)
bridge privado futuro
    media ingest plane: provider → H.264/Opus
```

`source_type` descreve o conteúdo (`youtube` ou `iptv`); LiveKit é transporte, nunca `source_type`. O backend carrega configuração opcional, produz nomes/identidades opacos e expõe `POST /room/{code}/livekit/viewer-token`. A rota exige participante vigente, transmissão `iptv`/`live` e revisão atual antes de emitir uma credencial com dez minutos de validade. Nenhum Ingress ou lifecycle de bridge foi integrado ao fluxo produtivo.

Na Etapa 10B.2, o polling continua sendo o plano de controle e o browser passa a ser consumidor do plano de mídia:

```text
room-presence.js
    → transmission iptv/live + revision
POST /room/{code}/livekit/viewer-token
    → URL + JWT efêmero + publisher esperado
room-livekit-player.js
    → LiveKit WebRTC, autoSubscribe=false
tracks somente do publisher esperado
```

O SDK `livekit-client` 2.22.3 é distribuído localmente e carregado apenas para `iptv`/`live`. YouTube e LiveKit possuem mounts e status independentes. Mudança de fonte, revisão ou encerramento incrementa uma geração local, desconecta a room anterior e descarta token, publisher e tracks antigos. IPTV Live não participa de Play/Pause/Seek, DVR, live edge ou resync compartilhado nesta etapa; volume, mute e fullscreen permanecem locais.

O nome da room LiveKit deriva deterministicamente de namespace e ID interno da sala. A publisher identity é vinculada à instância da transmissão e inclui room ID, revision e o `started_at` imutável: isso impede reuso após `end` seguido de novo `start`, mesmo quando a nova linha reinicia em revision 1. Viewer identities são aleatórias por emissão para permitir duas abas independentes. Nenhum desses identificadores cria owner, host, moderator ou hierarquia no Semyra. A autoridade temporária de playback continua pertencendo exclusivamente à transmissão vigente.

O SDK PHP permanece resolvido em `agence104/livekit-server-sdk` 1.3.5. A auditoria da distribuição registra a inconsistência sem interpretação jurídica: Composer metadata declares MIT; distributed LICENSE file is Apache-2.0. O arquivo `LICENSE` acompanha o runtime no mirror de produção.

## Fluxo de autenticação opcional

```text
GET/POST /register, GET/POST /login, POST /logout
    ↓
AuthController
    ├── Validator
    ├── UserRepository → users
    └── AuthSession → auth_user_id
```

O e-mail é aparado e normalizado para lowercase antes de consultas e inserções, enquanto a constraint `UNIQUE` case-insensitive do MySQL resolve corridas entre cadastros. A senha nunca é persistida em texto: o cadastro usa `password_hash(PASSWORD_DEFAULT)` e o login usa `password_verify()` com mensagem genérica para qualquer credencial inválida.

`AuthSession` é deliberadamente separada de `RoomParticipantSession`. Login e logout regeneram o ID da sessão, mas alteram somente `auth_user_id`; identidades físicas por sala continuam intactas. `RoomParticipantSession` guarda `participant_key`, nome e `user_id` opcional. Uma identidade guest pode ser vinculada à conta preservando a chave; a mesma conta reutiliza sua chave, enquanto uma troca de conta sempre gera outra. Ao criar uma sala, um usuário autenticado válido é registrado em `rooms.created_by_user_id`; isso define persistência, não controle do player.

`GET /rooms` usa `MyRoomsController` para exigir uma conta válida e carregar duas consultas sem N+1: `RoomRepository::createdByUser()` lista salas persistentes criadas pela conta, enquanto `UserRoomRepository::participatedByUser()` lista outras salas em que ela participou. `user_rooms` começa a ser preenchida nesta etapa, sem tentar associar registros anônimos antigos.

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

O gerador cria códigos públicos aleatórios e o repository tenta inserir cada código; colisões da constraint `UNIQUE` permitem até cinco novas tentativas no controller. A sala nasce vazia. `created_by_user_id = NULL` identifica uma sala temporária, que fica inacessível após 24 horas sem join ou presence válidos. Com usuário autenticado válido, o ID é persistido e a sala não expira por inatividade.

`RoomRepository::findByCode()` aplica a expiração lógica para todos os controllers. `touchActivity()` usa throttle atômico de 60 segundos no SQL, evitando uma escrita por poll. A limpeza física é oportunística na criação, na consulta direta de um código expirado e uma vez ao abrir “Minhas salas”; os relacionamentos existentes removem participantes, transmissão e histórico por cascade. Login posterior não reivindica sala temporária e logout não altera a persistência já definida.

`RoomController::show()` restaura automaticamente a identidade autenticada quando a sala foi criada pela conta, consta em `user_rooms` ou já possui identidade guest/da mesma conta na sessão. O nome vem sempre de `users.display_name`; salas novas para a conta mostram apenas uma confirmação sem campo editável. A restauração registra a combinação usuário/sala por UPSERT, mas não toca `rooms.last_activity_at`: somente join e presence válidos renovam o TTL.

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

Quem inicia torna-se proprietário da transmissão vigente. Outra pessoa pode substituí-la, incrementando `revision` e assumindo a propriedade. Somente o proprietário atual pode encerrá-la. O criador persistido da sala não recebe privilégios de playback; não há host permanente ou histórico de transmissões.

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

O layout `layouts/room` é fullscreen e independente do layout tradicional da aplicação. O frontend recebe uma apresentação pública escapada da transmissão, sem `room_id`, hash, token ou ID de participante. `room-shell.js` coordena HUD, dialog, painéis, fullscreen e áudio locais. `room-player.js` cria, troca ou destrói o player conforme eventos internos de transmissão.

O player permanece 16:9 e usa `contain`, centralizado sobre fundo preto; portanto telas retrato, paisagem curta e ultrawide preservam o quadro completo em vez de recortá-lo. `room-player.js` continua sendo o único módulo com acesso ao `YT.Player`: recebe os pedidos locais de volume do HUD, persiste somente o nível em `semyra:player-volume` e emite `semyra:player-audio-state` com `{muted, volume}`. Toda nova carga começa muda, independentemente do nível salvo.

`room-wake-lock.js` é uma melhoria progressiva separada. Ele observa apenas a presença de transmissão, o estado oficial playing/paused e o ciclo de visibilidade/fullscreen; solicita `navigator.wakeLock` quando a transmissão está reproduzindo em uma página visível e libera em pausa, fim, ocultação ou `pagehide`. A API não participa da autoridade, revisão, polling, telemetria ou correção de drift, e sua ausência ou recusa não afeta o playback.

A identidade visual da sala depende somente de assets públicos: a logo oficial fica em `public/assets/images/` e a view fornece um sprite SVG inline, sem CDN ou pacote de ícones. O JavaScript alterna visibilidade e atributos dos ícones já renderizados; o `YT.Player` e o estado compartilhado permanecem encapsulados e independentes dessa camada visual.

O compartilhamento permanece exclusivamente no frontend e reutiliza a rota pública existente:

```text
room.php
    ↓
room-share.js
    ↓
Clipboard API / Web Share API
```

`room-share.js` recebe somente o código público escapado para compor o título de compartilhamento. A URL é derivada de `window.location` como `/room/{code}`, sem query string ou fragment, e não é armazenada nem enviada a um endpoint próprio. A Clipboard API é opcional, com seleção manual do campo como fallback; a Web Share API é uma melhoria progressiva.

Antes de entrar, guests recebem o formulário de apelido; contas recebem apenas a confirmação do nome fixo. O fluxo de identidade física continua separado por sala na sessão do navegador:

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

A chave real nunca é enviada ao frontend nem persistida no banco. O repository grava apenas o hash, o nome, `user_id` opcional e `last_seen_at`; a constraint composta impede duplicação da mesma identidade física. Contas podem ter várias chaves/dispositivos, portanto `(room_id, user_id)` não é único. A listagem ativa deduplica contas no SQL pela linha mais recente e mantém guests distintos, sem N+1.

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

Na saída real da página, `room-presence.js` envia um leave best-effort no evento `pagehide`, preferindo `sendBeacon` e usando `fetch keepalive` como fallback. O `UPDATE` exige sala, participante e hash da instância atual; assim um leave atrasado da instância anterior não inativa a página nova após reload. O leave apenas antecipa `last_seen_at` e limpa a telemetria correspondente, sem apagar a identidade da sessão, encerrar transmissão ou alterar ownership. A janela de 45 segundos permanece como fallback obrigatório. Troca de aba não envia leave, e uma restauração via BFCache registra novamente a mesma instância do documento.

A resposta pública não contém IDs internos, hashes, tokens de sessão ou timestamps. Contas recebem `public_id` opaco estável derivado com namespace interno do `user_id`; guests continuam derivados do hash da chave. `playback_instance_id` identifica a geração física atual do documento/player. Nenhum desses IDs autentica ou autoriza. `is_you` compara `user_id` para contas e hash somente para guests. `transmission` contém fonte, video ID, revisão, nome do owner, `is_owner`, `media_mode` e playback oficial.

```text
room-presence.js
    ↓ /presence
participants + telemetry + transmission/playback
    ↓ semyra:shared-playback-updated
room-player.js
    ↓ owner ao vivo: getCurrentTime ocasional junto à presença
live_edge_position_ms
```

O owner envia comandos por `POST /room/{code}/transmission/playback`. Cada mutação usa compare-and-swap por `room_id`, revisões e uma condição exclusiva: `owner_user_id = current_user_id` para contas, ou, somente quando `owner_user_id IS NULL`, o hash da identidade guest. Logout remove imediatamente a autorização da transmissão account-owned; novo login na mesma conta a restaura mesmo com outra chave. Live edge segue a mesma regra. Transmissões legacy podem receber `owner_user_id` apenas por um `UPDATE` condicionado ao hash owner exato ainda presente na sessão; criador da sala, histórico, nome e timestamps nunca autorizam claim.

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

Este fluxo de telemetria continua observacional e não define autoridade. O playback oficial é aplicado quando muda a revisão da transmissão, a revisão do playback ou quando a primeira âncora se torna pronta; a projeção seguinte não redispara seek. Não existe seek periódico, eleição, consenso, playback rate, correção contínua de drift ou histórico de amostras. A margem de 5 segundos ainda está em validação empírica. Volume, mute, fullscreen e Wake Lock continuam exclusivamente locais.

Como experimento de estabilização, o owner dispõe de `Sincronizar`: em conteúdo playing, o frontend serializa `pause`, confirmação oficial, espera de 2 segundos e `play` — ou `live` quando estava no ponto AO VIVO. Em paused, republica a posição por `seek` e não inicia reprodução. Revisions e ownership são revalidados antes da segunda ação; conflito, substituição ou perda de ownership cancelam a retomada antiga. Viewers continuam reagindo somente às revisions oficiais.

O owner também pode executar esse pulso automaticamente por nova coorte de instâncias de player ainda não cobertas na revision vigente. Cada `playback_instance_id` precisa permanecer ativo e com telemetria fresh por quatro segundos; chegadas próximas são agrupadas numa única barreira, e somente os IDs ready capturados no início são marcados após sucesso. Um reload preserva `public_id`, mas cria outro `playback_instance_id`, portanto a nova instância recebe warmup e uma única barreira. Saída observada remove a cobertura para permitir novo warmup no rejoin. Em paused, o estado determinístico estabilizado funciona como barreira natural sem Pause/Play. A cobertura local guarda apenas IDs públicos opacos em memória e `sessionStorage`, limitada à revision atual; IDs antigos são descartados quando deixam de corresponder às instâncias ativas. Posições e drift não entram na decisão nem se tornam autoridade; polling da mesma instância não repete a barreira.

## Ferramentas de infraestrutura

Os scripts em `bin/`, herdados da base técnica inicial, não fazem parte do fluxo HTTP nem das regras de negócio. `composer setup` prepara uma cópia local conservadoramente. `composer deploy:hostgator` gera, a partir de uma allowlist versionada, um espelho descartável de produção. O espelho nunca se torna uma segunda fonte de código.
