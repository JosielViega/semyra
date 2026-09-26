# Semyra

**Assista junto.**

Semyra é uma plataforma em desenvolvimento para amigos criarem salas virtuais e assistirem conteúdos juntos, mesmo à distância. A sala existe independentemente da mídia e pode ter uma única transmissão ativa, iniciada por qualquer participante.

## Estado atual

- criação de sala vazia, sem vínculo permanente com uma mídia;
- validação local da estrutura da URL e extração do video ID;
- geração segura de código público e persistência da sala no MySQL/MariaDB;
- transmissão ativa separada da sala, com YouTube como primeira fonte;
- proprietário temporário definido por quem iniciou a transmissão vigente;
- substituição da transmissão por qualquer participante, com incremento de revisão;
- estado oficial de Play/Pause/Seek controlado pelo proprietário da transmissão, com modo Vídeo/Ao vivo escolhido explicitamente;
- YouTube VOD com duração convencional e YouTube Live com DVR, distância relativa e ação compartilhada `AO VIVO`;
- página imersiva fullscreen com player responsivo, HUD temporário e overlays;
- controles nativos do YouTube ocultos, com mute e fullscreen locais do Semyra;
- compartilhamento da sala pela própria URL pública, com cópia automática quando a Clipboard API está disponível e seleção manual como fallback;
- Web Share API como melhoria progressiva em navegadores compatíveis;
- entrada anônima por apelido, isolada por sala e sessão do navegador;
- lista de participantes ativos, atualizada por polling a cada 5 segundos sem transmissão e a cada 1 segundo durante uma transmissão;
- telemetria observacional do player, com estado, posição e duração transportados junto à presença;
- medição aproximada do desvio entre participantes disponível somente no modo de diagnóstico;
- infraestrutura de rotas, controllers, repositories, views, PDO, sessões, CSRF, logs, migrations, testes e CI;
- `GET /health` disponível como health check simples;
- verificação prévia, no backend, da existência ou do estado da Live ainda não implementada;
- correção contínua de drift, chat e autenticação ainda não implementados;
- compartilhamento de tela é uma fonte futura adicional; não substitui a integração permanente com YouTube e seu pipeline ainda não foi definido.

## Stack

- PHP 8.2 ou superior, PDO MySQL e Composer 2;
- MySQL 8+ ou MariaDB compatível;
- Apache com `mod_rewrite` e `.htaccess`;
- HTML5, CSS3 e JavaScript puro;
- PHPUnit para testes.

O projeto não usa framework PHP, ORM, framework JavaScript ou etapa de build frontend.

## Instalação

```bash
git clone https://github.com/JosielViega/semyra.git
cd semyra
composer install
composer setup
```

`composer setup` cria `.env` a partir de `.env.example` somente quando ele não existe, escolhe uma porta local livre e não reservada por outro projeto, grava a reserva local e atualiza o autoload. Um `.env` existente é preservado; quando necessário, somente `APP_PORT` e uma `APP_URL` local podem ser ajustados.

Também é possível criar o ambiente manualmente:

```powershell
copy .env.example .env
```

No Linux/macOS:

```bash
cp .env.example .env
```

Preencha as configurações locais. O `.env` nunca deve ser versionado. Ao criar classes, regenere o autoload:

```bash
composer dump-autoload
```

### MySQL local com Docker

O Docker Compose é usado somente para executar o MySQL 8.4 no desenvolvimento local. PHP e Composer continuam sendo executados diretamente no host, e a produção HostGator não utiliza Docker.

Crie o arquivo local ignorado `.env.docker` com as variáveis descritas em [desenvolvimento local](docs/LOCAL_DEVELOPMENT.md), configure as mesmas credenciais de aplicação no `.env` do PHP e execute:

```bash
docker compose --env-file .env.docker up -d mysql
docker compose --env-file .env.docker ps
```

Depois que o banco estiver saudável, execute `composer migrate`. Os comandos para parar, reiniciar e remover o container sem apagar o volume também estão no guia de desenvolvimento local.

## Porta local

Cada projeto recebe uma porta própria. O setup exige que a porta não esteja reservada para outro projeto nem ocupada por um listener. As reservas continuam no registro técnico compartilhado entre projetos derivados da mesma base: `~/.modeloPHP/ports.json` (no Windows, dentro do perfil do usuário).

```env
APP_URL=http://localhost:8010
APP_PORT=8010
```

`composer serve` valida a faixa, confere divergências com o registro e testa o listener. Se a porta estiver ocupada, o comando termina sem encerrar o processo existente. Consulte [desenvolvimento local](docs/LOCAL_DEVELOPMENT.md).

## Executar a aplicação

```bash
composer serve
```

Acesse o endereço informado pelo comando. O servidor embutido é uma conveniência local; Apache é o ambiente esperado em produção.

## Comandos do projeto

```bash
composer test
composer lint
composer check
composer migrate
composer port:status
composer port:release
composer deploy:hostgator
```

- `test` executa a suíte PHPUnit;
- `lint` valida a sintaxe dos arquivos PHP próprios;
- `check` executa `composer validate --strict`, lint e testes;
- `migrate` cria a tabela de controle e executa migrations SQL pendentes;
- `port:status` exibe a reserva e a configuração do projeto atual;
- `port:release` libera apenas a reserva deste projeto, sem alterar `.env` ou processos;
- `deploy:hostgator` valida o projeto e gera `deploy/hostgator/mirror/`, fora do Git.

## Estrutura

```text
app/                 Núcleo, controllers e futuro código de domínio
bootstrap/app.php    Composição e inicialização da aplicação
config/              Configuração derivada do ambiente
database/            Migrations SQL e seeds opcionais
docs/                Arquitetura, segurança e ambiente local
public/              Único Document Root público
resources/views/     Layouts, componentes e páginas PHP
routes/web.php       Rotas HTTP explícitas
storage/             Cache e logs locais
tests/               Testes unitários sem banco externo
bin/                 Comandos operacionais do projeto
deploy/hostgator/     Manifesto e documentação do mirror de produção
```

Para compreender a base técnica e revisar seus fluxos, consulte o [plano de estudo e revisão](docs/PLANO_DE_ESTUDO_E_REVISAO.md), herdado da estrutura inicial.

## Rotas atuais

- `GET /` — formulário para criar uma sala vazia;
- `POST /rooms` — gera o código e persiste uma nova sala vazia;
- `GET /room/{code}` — exibe uma sala existente e carrega seu conteúdo no YouTube Player;
- `POST /room/{code}/join` — valida o apelido e registra a identidade anônima da sessão na sala;
- `POST /room/{code}/presence` — renova a presença e retorna a lista pública de participantes ativos;
- `POST /room/{code}/transmission` — inicia ou substitui a transmissão YouTube ativa;
- `POST /room/{code}/transmission/end` — encerra a transmissão quando solicitado pelo proprietário atual;
- `POST /room/{code}/transmission/playback` — aplica Play, Pause ou Seek por compare-and-swap do proprietário atual;
- `GET /health` — retorna `{"status":"ok"}` sem detalhes internos;
- demais caminhos — página 404 com status correto.

As rotas ficam em `routes/web.php`. O Router também está preparado para rotas parametrizadas e para PUT, PATCH e DELETE por `_method` em um POST, embora o Semyra ainda não utilize esses fluxos.

## Controllers, views e domínio

Controllers recebem a requisição, coordenam o caso HTTP e escolhem uma `Response`. HTML extenso fica em `resources/views`; valores dinâmicos devem ser impressos com `e()`:

```php
<h1><?= e($title) ?></h1>
```

`RoomController` cria e consulta salas independentes de mídia. `RoomTransmissionController` inicia, substitui e encerra a transmissão ativa e recebe comandos do playback oficial; `RoomTransmissionRepository` mantém no máximo uma transmissão por sala e protege comandos com owner e revisões esperadas. `RoomParticipantController` trata entrada e presença; `RoomParticipantSession` mantém uma chave aleatória por sala na sessão, enquanto o banco recebe somente seu hash SHA-256. `YouTubeUrlParser` valida a primeira fonte suportada. Na sala, `room-presence.js` transporta presença, telemetria, transmissão e observações moderadas da borda ao vivo em um único polling dinâmico; `room-media.js` contém somente funções puras de apresentação; `room-playback.js` coordena os controles compartilhados sem acessar o player; `room-player.js` mantém o `YT.Player` privado; `room-shell.js` coordena HUD, overlays, mute e fullscreen locais. Não há ORM.

A sala não possui dono. A transmissão ativa possui um proprietário temporário: quem iniciou a mídia vigente. Qualquer participante pode substituí-la e assumir automaticamente essa propriedade. Somente esse owner inicializa e envia Play/Pause/Seek/AO VIVO ao estado oficial; viewers aplicam as revisões confirmadas pelo servidor. Mute e fullscreen permanecem locais. Não existem host permanente, eleição ou correção contínua de drift.

## Banco e migrations

`App\Core\Database` cria PDO sob demanda com exceptions, fetch associativo, prepared statements nativos e `utf8mb4`. As credenciais vêm exclusivamente do ambiente.

As migrations SQL versionadas ficam em `database/migrations/`. `composer migrate` executa cada arquivo ainda não registrado uma única vez. Faça backup e teste alterações de schema antes de produção.

A tabela `rooms` contém somente:

- `id`: chave primária incremental;
- `code`: código público ASCII de 8 caracteres, com índice `UNIQUE`;
- `created_at`: data de criação definida pelo banco.

A tabela `room_transmissions` relaciona uma sala a zero ou uma transmissão ativa. Ela mantém fonte, video ID do YouTube, modo de mídia (`vod`/`live`, com `unknown` apenas como default técnico de migração), hash do proprietário temporário, revisão da transmissão e o estado oficial de playback (`playing`/`paused`, posição, indicador de borda ao vivo, revisão própria e instante-base). A posição pública e a âncora de borda são projetadas pelo relógio do MySQL. Live inicia na posição natural do YouTube e usa DVR; `getDuration()` não detecta Live nem define sua borda. Não existe histórico nesta etapa. A URL completa não é armazenada; o parser não consulta o YouTube nem confirma disponibilidade ou permissão de incorporação.

A tabela `room_participants` associa uma identidade anônima a uma sala. O navegador guarda uma chave aleatória de 256 bits na sessão; o banco recebe somente o hash SHA-256 dessa chave, o apelido e os horários necessários para calcular presença. Participantes são considerados ativos por 45 segundos após `last_seen_at`. A mesma linha mantém somente o último snapshot do player (`player_state`, posição e duração em milissegundos e o horário de recebimento no banco); não existe histórico de telemetria.

## Segurança

- secrets somente no `.env`, nunca no Git;
- prepared statements, sem concatenar input em SQL;
- escape HTML com `e()`;
- CSRF em toda ação que muda estado;
- cookies HttpOnly, SameSite=Lax, modo estrito e Secure configurável;
- mensagens genéricas em produção e detalhes nos logs;
- uploads ignorados e execução de PHP bloqueada em `public/uploads`;
- validação no backend e ações mutáveis fora de GET.

Leia a política completa em [docs/SECURITY.md](docs/SECURITY.md).

## Produção e HostGator

Use pelo menos:

```env
APP_ENV=production
APP_DEBUG=false
SESSION_SECURE=true
```

Instale dependências com `composer install --no-dev --classmap-authoritative`, conceda escrita apenas a `storage/` e aos diretórios de upload necessários, configure HTTPS e aponte o Document Root para `public/`.

Quando a hospedagem não permitir alterar o Document Root, mantenha o projeto fora de `public_html`, copie apenas o conteúdo público para a área servida e ajuste o front controller para a localização privada real. Não exponha `.env`, `vendor` ou código interno.

`composer deploy:hostgator` gera um mirror com dependências de produção. Ele não inclui `.env`, `.htaccess`, configurações PHP do servidor, uploads, logs ou cache; também não envia arquivos, remove conteúdo remoto ou executa migrations. Consulte o [guia de deploy HostGator/cPanel](deploy/hostgator/README.md).

## Documentação técnica

- [Arquitetura](docs/ARCHITECTURE.md)
- [Desenvolvimento local](docs/LOCAL_DEVELOPMENT.md)
- [Segurança](docs/SECURITY.md)
- [Deploy HostGator/cPanel](deploy/hostgator/README.md)
