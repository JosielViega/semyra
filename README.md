# Semyra

**Assista junto.**

Semyra é uma plataforma em desenvolvimento para amigos criarem salas virtuais e assistirem conteúdos juntos, mesmo à distância. O primeiro MVP usa a URL de um vídeo ou Live existente do YouTube para criar uma sala compartilhável e reproduzir o conteúdo na própria página da sala.

## Estado atual

- criação de sala por URL de vídeo ou Live do YouTube;
- validação local da estrutura da URL e extração do video ID;
- geração segura de código público e persistência da sala no MySQL/MariaDB;
- página acessível por `GET /room/{code}` com YouTube Player responsivo;
- reprodução pela YouTube IFrame Player API, iniciada somente por interação do usuário;
- controles nativos do YouTube e tratamento visual de erros básicos de incorporação;
- infraestrutura de rotas, controllers, repositories, views, PDO, sessões, CSRF, logs, migrations, testes e CI;
- `GET /health` disponível como health check simples;
- verificação prévia, no backend, da existência ou do estado da Live ainda não implementada;
- participantes ainda não implementados;
- convite/cópia de link, sincronização, chat e autenticação ainda não implementados.
- controle remoto do player ainda não implementado.

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

- `GET /` — formulário para criar uma sala com uma URL suportada do YouTube;
- `POST /rooms` — valida a URL, gera o código e persiste a nova sala;
- `GET /room/{code}` — exibe uma sala existente e carrega seu conteúdo no YouTube Player;
- `GET /health` — retorna `{"status":"ok"}` sem detalhes internos;
- demais caminhos — página 404 com status correto.

As rotas ficam em `routes/web.php`. O Router também está preparado para rotas parametrizadas e para PUT, PATCH e DELETE por `_method` em um POST, embora o Semyra ainda não utilize esses fluxos.

## Controllers, views e domínio

Controllers recebem a requisição, coordenam o caso HTTP e escolhem uma `Response`. HTML extenso fica em `resources/views`; valores dinâmicos devem ser impressos com `e()`:

```php
<h1><?= e($title) ?></h1>
```

`RoomController` coordena a criação e consulta de salas. `YouTubeUrlParser` valida localmente os formatos suportados, `RoomCodeGenerator` cria códigos públicos e `RoomRepository` concentra o SQL preparado do domínio. Na sala, `room-player.js` recebe somente o `youtube_video_id` escapado pela view e cria o player pela IFrame Player API. Não há ORM.

Não existem participantes, usuários, autenticação, convite/cópia, sincronização, controle remoto ou chat nesta etapa. O player não inicia automaticamente e utiliza os controles nativos do YouTube.

## Banco e migrations

`App\Core\Database` cria PDO sob demanda com exceptions, fetch associativo, prepared statements nativos e `utf8mb4`. As credenciais vêm exclusivamente do ambiente.

As migrations SQL versionadas ficam em `database/migrations/`. `composer migrate` executa cada arquivo ainda não registrado uma única vez. Faça backup e teste alterações de schema antes de produção.

A tabela `rooms` contém somente:

- `id`: chave primária incremental;
- `code`: código público ASCII de 8 caracteres, com índice `UNIQUE`;
- `youtube_video_id`: identificador ASCII de 11 caracteres;
- `created_at`: data de criação definida pelo banco.

A URL completa não é armazenada. O parser não consulta o YouTube e não confirma se o vídeo existe, está ao vivo, é público ou permite incorporação.

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
