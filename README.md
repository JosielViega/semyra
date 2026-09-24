# Semyra

**Assista junto.**

Semyra será uma plataforma para amigos criarem salas virtuais e assistirem conteúdos juntos, mesmo à distância. O primeiro MVP usará uma Live existente do YouTube incorporada a uma sala compartilhável. O desenvolvimento é incremental: nesta etapa, o projeto possui a base estrutural e a identidade inicial, mas ainda não cria salas, não incorpora o YouTube Player e não gerencia participantes.

## Estado atual

- base estrutural PHP pronta;
- identidade inicial do Semyra aplicada;
- infraestrutura de rotas, controllers, views, PDO, validação, sessões, CSRF, logs, migrations, testes e CI disponível;
- `GET /health` disponível como health check simples;
- criação de salas ainda não implementada;
- YouTube Player ainda não implementado;
- participantes ainda não implementados.

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

- `GET /` — página inicial do Semyra e prévia visual, não funcional, da futura criação de sala;
- `GET /health` — retorna `{"status":"ok"}` sem detalhes internos;
- demais caminhos — página 404 com status correto.

As rotas ficam em `routes/web.php`. O Router também está preparado para rotas parametrizadas e para PUT, PATCH e DELETE por `_method` em um POST, embora o Semyra ainda não utilize esses fluxos.

## Controllers, views e domínio

Controllers recebem a requisição, coordenam o caso HTTP e escolhem uma `Response`. HTML extenso fica em `resources/views`; valores dinâmicos devem ser impressos com `e()`:

```php
<h1><?= e($title) ?></h1>
```

Repositories devem concentrar consultas SQL explícitas de um assunto do domínio. Services só devem existir quando houver regra de negócio ou integração que justifique a camada. Models podem ser objetos simples; não há ORM.

Nenhuma classe, migration ou tabela de negócio de salas, participantes, usuários ou vídeos existe nesta etapa.

## Banco e migrations

`App\Core\Database` cria PDO sob demanda com exceptions, fetch associativo, prepared statements nativos e `utf8mb4`. As credenciais vêm exclusivamente do ambiente.

Adicione migrations SQL versionadas a `database/migrations/` com nomes ordenáveis. `composer migrate` executa cada arquivo ainda não registrado uma única vez. Faça backup e teste alterações de schema antes de produção.

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
