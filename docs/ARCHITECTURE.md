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

`source_type` descreve o conteúdo (`youtube` ou `iptv`); LiveKit é transporte, nunca `source_type`. O backend carrega configuração opcional, produz nomes/identidades opacos e expõe `POST /room/{code}/livekit/viewer-token`. A rota exige participante vigente, transmissão `iptv`/`live`, `instance_id` e revisão atuais antes de emitir uma credencial com dez minutos de validade.

Na Etapa 10B.2, o polling continua sendo o plano de controle e o browser passa a ser consumidor do plano de mídia:

```text
room-presence.js
    → transmission iptv/live + instance_id + revision
POST /room/{code}/livekit/viewer-token
    → URL + JWT efêmero + publisher esperado
room-livekit-player.js
    → LiveKit WebRTC, autoSubscribe=false
tracks somente do publisher esperado
```

O SDK `livekit-client` 2.22.3 é distribuído localmente e carregado apenas para `iptv`/`live`. YouTube e LiveKit possuem mounts e status independentes. Mudança de fonte, `instance_id`, revisão ou encerramento incrementa uma geração local, desconecta a room anterior e descarta token, publisher e tracks antigos. IPTV Live não participa de Play/Pause/Seek, DVR, live edge ou resync compartilhado nesta etapa; volume, mute e fullscreen permanecem locais.

O nome da room LiveKit deriva deterministicamente de namespace e ID interno da sala. A publisher identity é vinculada à instância da transmissão e deriva de namespace, room ID e `instance_id`; `started_at` continua somente como timestamp observacional. Isso impede reuso após `end` seguido de novo `start`, mesmo quando a nova linha reinicia em revision 1. Viewer identities são aleatórias por emissão para permitir duas abas independentes. Nenhum desses identificadores cria owner, host, moderator ou hierarquia no Semyra. A autoridade temporária de playback continua pertencendo exclusivamente à transmissão vigente.

O SDK PHP permanece resolvido em `agence104/livekit-server-sdk` 1.3.5. A auditoria da distribuição registra a inconsistência sem interpretação jurídica: Composer metadata declares MIT; distributed LICENSE file is Apache-2.0. O arquivo `LICENSE` acompanha o runtime no mirror de produção.

### Control plane do bridge IPTV

Na Etapa 10B.3A, o aplicativo PHP/MySQL da HostGator é o control plane e mantém somente desired state, estado do job, lease hash e `ingress_id`. O LiveKit continua sendo o media plane. Um bridge worker externo usa protocolo pull por HTTPS; a HostGator nunca chama o worker e o worker não abre API pública.

```text
bridge worker externo
    → claim autenticado por X-Semyra-Worker-Token
Semyra control plane
    → LiveKit CreateIngress (WHIP, bypassTranscoding=true)
    → endpoint WHIP efêmero
bridge worker
    → heartbeat/report com lease fencing
```

O Semyra deriva room, nome do Ingress e publisher pelos serviços existentes, cria e remove o WHIP Ingress com `bypassTranscoding=true` e persiste somente seu ID. O nome `smy_b_<instance_id>_a<attempt_count>` dá a cada claim uma geração externa distinta; room e publisher continuam vinculados somente à transmission instance. Antes de criar a geração N, o control plane limpa somente o intervalo ainda não confirmado entre `cleanup_through_attempt + 1` e `N - 1`, sempre por nomes exatos. Cleanup da geração atual avança o watermark até N somente depois que todos os recursos do intervalo forem confirmados ausentes ou removidos. Isso recupera um crash ocorrido depois de `CreateIngress` e antes de persistir `ingress_id`, pois nesse ponto o watermark continua em N-1. O ID persistido é uma otimização/handle, não a única fonte para cleanup. O `room_id` do job é um snapshot operacional sem FK e sobrevive à exclusão da sala, permitindo refazer o mesmo contexto determinístico no stop e na reconciliação.

Na 10B.3B, o feeder externo resolve `source_ref` por igualdade exata em um catálogo privado local e abre uma única conexão HTTP/HTTPS para a fonte MPEG-TS. Os primeiros bytes dessa mesma conexão são validados pelos sync bytes do transport stream e, sem descarte do buffer inicial, seguem pelo stdin de um container efêmero: `fdsrc` → `tsdemux`; H.264 passa por `h264parse`/`rtph264pay` sem decode ou reencode, enquanto AAC é decodificado e convertido para Opus 48 kHz estéreo a 96 kbps antes da publicação WHIP.

A credencial WHIP e o lease token bruto permanecem somente em memória; o banco guarda apenas SHA-256 do lease. O endpoint WHIP entra no container por ambiente herdado e `docker run -e WHIP_ENDPOINT`, nunca como valor literal em argv. O worker nunca recebe `LIVEKIT_API_KEY` ou `LIVEKIT_API_SECRET`, e credenciais/origens do provider permanecem exclusivamente do lado do worker. `source_ref` é um identificador opaco de catálogo privado, não uma URL ou credencial.

Jobs usam `transmission_instance_id` como fence da transmissão, `attempt_count` exclusivamente como geração monotônica do worker e dos efeitos externos no Ingress, `failure_count` como orçamento de falhas reais e `cleanup_through_attempt` como maior geração confirmada ausente no LiveKit. Claim, heartbeat, report, renew, avanço do watermark e mutations do ingress exigem worker, job, instância e lease atual. Uma operação conserva o attempt do snapshot daquela lease e nunca procura gerações futuras; assim, um cleanup atrasado de `_a1` não descobre nem remove `_a2`. `worker_shutdown` preserva `failure_count`, enquanto falhas reais e expiração de lease incrementam esse contador exatamente uma vez; novos claims exigem `failure_count` abaixo de `MEDIA_BRIDGE_MAX_FAILURES`. Se listing ou qualquer delete falhar, o watermark não avança, o job registra `ingress_cleanup_pending` e nenhuma nova criação é permitida. End, replacement, mudança para uma fonte que não seja IPTV Live ou exclusão da sala levam o job a `stopping`; nesse estado sem lease ativa, a reconciliação limpa somente as gerações acima do watermark até o snapshot de `attempt_count`, mesmo quando `ingress_id` está nulo.

`MEDIA_BRIDGE_MAX_ATTEMPTS` é aceito temporariamente apenas como fallback quando `MEDIA_BRIDGE_MAX_FAILURES` não está definido; o novo nome sempre tem prioridade e é o único mostrado em `.env.example`.

O diretório `bridge-worker/` é um artefato de implantação separado e não entra no mirror HostGator. Um processo aceita no máximo um job ativo: `--once` processa um claim e `--loop` repete claims sequencialmente; `--dry-run` preserva a prova sem mídia e `--check` valida Docker, imagem e plugins sem claim. Heartbeats renovam um watchdog local e um safety deadline conservador. `stop`, perda de lease ou indisponibilidade além desse deadline fecham feeder e container antes de qualquer novo claim. O worker não recebe `failure_count`, limite de falhas ou watermark: esses detalhes pertencem apenas ao control plane. O pacote Linux usa releases imutáveis em `/opt/semyra-bridge`, configuração privada em `/etc/semyra` e runtime efêmero configurável em `/run/semyra-bridge`; a unit systemd executa o check antes do loop e preserva shutdown cooperativo por SIGTERM. Ainda não há seleção ou UI IPTV no produto.

Shutdown local do serviço não equivale a stop da transmissão. `SIGTERM`/`SIGINT` interrompem mídia e watchdog pelo cleanup normal e tentam reportar `failed/worker_shutdown`, preservando `desired_state=running` e o orçamento de falhas para reclaim em nova geração. Já `action=stop` do control plane continua reportando `stopped`; perda de lease tem prioridade de fencing e não permite report do owner antigo. A unit systemd está versionada para validação, mas nenhum host foi provisionado ou ativado.

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

O PHPSESSID permanece um cookie de sessão. Quando solicitado, `RememberMeService` emite `selector.validator` em cookie HttpOnly/SameSite=Lax e persiste apenas selector e SHA-256 do validator. Antes do dispatch, um token válido restaura uma nova sessão por `AuthSession::login()` e rotaciona atomicamente o validator; token inválido, expirado ou órfão é revogado e o cookie é limpo. Logout protegido por CSRF revoga somente o token apresentado pelo dispositivo atual. O Desktop usa exatamente esse mecanismo HTTP, sem token ou CookieManager nativo.

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
    ├── instance_id opaco e imutável por execução
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

Quem inicia torna-se proprietário da transmissão vigente. Outra pessoa pode substituí-la, incrementando `revision` e assumindo a propriedade. Cada start ou replacement recebe um novo `instance_id` aleatório; ele identifica a execução específica, enquanto `revision` e playback revision versionam o estado dentro desse contexto. End, playback e observação de live edge usam compare-and-swap por owner, `instance_id` e revisions aplicáveis, de modo que uma ação atrasada nunca alcance a transmissão seguinte. O identificador de instância é público e opaco, mas não é credencial nem concede autoridade. Somente o proprietário atual pode encerrar a transmissão. O criador persistido da sala não recebe privilégios de playback; não há host permanente ou histórico de transmissões.

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

A resposta pública não contém IDs internos, hashes, tokens de sessão ou timestamps. Contas recebem `public_id` opaco estável derivado com namespace interno do `user_id`; guests continuam derivados do hash da chave. `playback_instance_id` identifica a geração física atual do documento/player. Nenhum desses IDs autentica ou autoriza. `is_you` compara `user_id` para contas e hash somente para guests. `transmission` contém o `instance_id` público opaco, fonte, video ID, revisão, nome do owner, `is_owner`, `media_mode` e playback oficial.

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

Este fluxo de telemetria continua observacional e não define autoridade. O playback oficial é aplicado quando muda o `instance_id`, a revisão da transmissão, a revisão do playback ou quando a primeira âncora se torna pronta; a projeção seguinte não redispara seek. Eventos atrasados são aceitos somente quando pertencem à mesma instância. Não existe seek periódico, eleição, consenso, playback rate, correção contínua de drift ou histórico de amostras. A margem de 5 segundos ainda está em validação empírica. Volume, mute, fullscreen e Wake Lock continuam exclusivamente locais.

Como experimento de estabilização, o owner dispõe de `Sincronizar`: em conteúdo playing, o frontend serializa `pause`, confirmação oficial, espera de 2 segundos e `play` — ou `live` quando estava no ponto AO VIVO. Em paused, republica a posição por `seek` e não inicia reprodução. Instance ID, revisions e ownership são revalidados antes da segunda ação; conflito, substituição ou perda de ownership cancelam a retomada antiga. Viewers continuam reagindo somente ao estado oficial da instância atual.

O owner também pode executar esse pulso automaticamente por nova coorte de instâncias de player ainda não cobertas na revision vigente. Cada `playback_instance_id` precisa permanecer ativo e com telemetria fresh por quatro segundos; chegadas próximas são agrupadas numa única barreira, e somente os IDs ready capturados no início são marcados após sucesso. Um reload preserva `public_id`, mas cria outro `playback_instance_id`, portanto a nova instância recebe warmup e uma única barreira. Saída observada remove a cobertura para permitir novo warmup no rejoin. Em paused, o estado determinístico estabilizado funciona como barreira natural sem Pause/Play. A cobertura local guarda apenas IDs públicos opacos em memória e `sessionStorage`, separada por endpoint, `instance_id` e revision; IDs de uma transmissão nunca são reutilizados pela seguinte. Posições e drift não entram na decisão nem se tornam autoridade; polling da mesma instância não repete a barreira.

## Direção desktop

A operação do bridge worker Linux/systemd da 10C.1B permanece documentada e válida como opção futura, mas a implantação 10C.2 em VPS está adiada. A direção atual para mídia hospedada pelo cliente combina o Semyra web e LiveKit Cloud com um aplicativo Windows local:

```text
Semyra Desktop
├── WebView/UI (aplicativo web existente)
└── Host Engine local (in-process)
```

Desde a fundação 11B, o Host Engine possui lifecycle mínimo ligado à janela (`Stopped`/`Ready`) e snapshot somente leitura. Na 11C, a ponte allowlisted adiciona `host.authorize` e `host.clear`: o owner atual de uma transmissão IPTV Live pode obter do backend uma Host Session curta de `media.publish`, ligada à sala, transmission instance, revision e identidade de ownership. O banco armazena somente o hash do validator; o token bruto permanece apenas na memória nativa, nunca aparece no snapshot e é removido na troca de contexto, em `clear` ou no `Stop()`.

Na 11D, fontes M3U e seu catálogo pertencem exclusivamente ao Host Engine. O SQLite `%LOCALAPPDATA%\Semyra\Data\semyra.db` guarda metadados locais; localização da fonte e URLs de stream são blobs protegidos por DPAPI `CurrentUser`. O parser processa Extended M3U em streaming e importa para tabela temporária antes da substituição transacional por fonte. A WebView recebe apenas DTOs seguros, pesquisa paginada e grupos, através de comandos explícitos da allowlist. Arquivos, credenciais, banco local e catálogo nunca atravessam o backend PHP nem o mirror HostGator.

Na 11E, o Media Engine recebe somente o ID local do canal. O native resolve SQLite → DPAPI → URL e mantém o segredo fora de argv, ambiente, logs, snapshots e eventos. O fluxo é `provider → HttpClient streaming → validação MPEG-TS → stdin → GStreamer`: H.264 segue sem reencode para RTP, AAC é decodificado e convertido para Opus 48 kHz estéreo a 96 kbps, e os dois ramos terminam temporariamente em `fakesink`. O lifecycle permite uma sessão por Host Engine, troca atômica de canal, stop idempotente e até três reconexões com backoff 1/2/5 s. A capability `iptv.play` depende da validação do runtime e dos plugins. O runtime definitivo será empacotado com o Desktop na distribuição; WHIP e LiveKit continuam reservados para a 11F.

A emissão usa fencing na própria inserção contra a transmissão corrente. Nenhum segredo global do bridge worker é enviado ao Desktop. Ainda não há Xtream, WHIP, publicação LiveKit de mídia local, servidor HTTP local ou backend adicional de media job. O engine poderá ser separado em outro processo no futuro se isso se tornar necessário.

## Ferramentas de infraestrutura

Os scripts em `bin/`, herdados da base técnica inicial, não fazem parte do fluxo HTTP nem das regras de negócio. `composer setup` prepara uma cópia local conservadoramente. `composer deploy:hostgator` gera, a partir de uma allowlist versionada, um espelho descartável de produção. `composer build:bridge-worker` gera outro artefato local e isolado, também por allowlist, sem upload ou ação remota. Nenhum desses outputs se torna uma segunda fonte de código.
