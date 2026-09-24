# Modelo PHP

Starter reutilizável para aplicações web tradicionais em PHP. Ele oferece uma base pequena, organizada e segura sem framework, ORM, Node.js ou Docker obrigatório. O código privilegia responsabilidades explícitas e é adequado a Apache, MySQL/MariaDB e hospedagem compartilhada.

## Stack

- PHP 8.2 ou superior, PDO MySQL e Composer 2
- MySQL 8+ ou MariaDB compatível
- Apache com `mod_rewrite` e `.htaccess`
- HTML5, CSS3 e JavaScript puro

## Composer

Composer é obrigatório desde o primeiro dia. Há um único `vendor/` na raiz, ignorado pelo Git, e o namespace `App\` usa PSR-4 em `app/`. As dependências são `vlucas/phpdotenv` em produção e PHPUnit em desenvolvimento.

## Instalação

```bash
git clone https://github.com/JosielViega/modeloPHP.git
cd modeloPHP
composer install
composer setup
```

`composer setup` cria `.env` a partir do exemplo somente quando ele não existe, escolhe uma porta local livre e não reservada por outro projeto, grava a reserva local e atualiza o autoload. Um `.env` existente é preservado; quando necessário, somente `APP_PORT` e uma `APP_URL` local podem ser ajustados.

Também é possível criar o arquivo local de ambiente manualmente:

```powershell
copy .env.example .env
```

No Linux/macOS:

```bash
cp .env.example .env
```

Preencha as configurações locais. Regenere o autoload quando criar classes:

```bash
composer dump-autoload
```

O `.env` contém valores locais e nunca deve ser versionado.

## Porta local

Cada projeto recebe uma porta própria. O setup combina duas proteções: a porta não pode estar reservada para outro projeto desligado nem ocupada por um listener ativo. As reservas ficam somente na máquina local em `~/.modeloPHP/ports.json` (no Windows, dentro do perfil do usuário).

```env
APP_URL=http://localhost:8010
APP_PORT=8010
```

`composer serve` valida a faixa, confere divergências com o registro e testa o listener antes de iniciar. Se estiver ocupada, o comando termina sem encerrar o processo existente. Consulte [desenvolvimento local](docs/LOCAL_DEVELOPMENT.md).

## Iniciar a aplicação

```bash
composer serve
```

Acesse o endereço mostrado pelo comando. O servidor embutido é apenas uma conveniência local; Apache é o ambiente esperado em produção.

## Comandos de qualidade

```bash
composer test
composer lint
composer check
composer port:status
composer port:release
```

`port:status` mostra somente a reserva e configuração do projeto atual. `port:release` libera somente sua reserva, sem alterar `.env` ou processos.

`composer check` executa `composer validate --strict`, valida a sintaxe dos arquivos PHP próprios e roda os testes. Para migrations SQL:

```bash
composer migrate
```

Para gerar uma pasta local pronta para atualização manual em hospedagem compartilhada:

```bash
composer deploy:hostgator
```

O mirror gerado fica em `deploy/hostgator/mirror/`, fora do Git. Consulte [deploy para HostGator/cPanel](deploy/hostgator/README.md).

## Estrutura

```text
app/                 Núcleo, controllers e código do domínio
bootstrap/app.php    Composição e inicialização da aplicação
config/              Configuração derivada do ambiente
database/            Migrations SQL e seeds opcionais
docs/                Arquitetura, segurança e ambiente local
public/              Único Document Root público
resources/views/     Layouts, componentes e páginas PHP
routes/web.php       Rotas HTTP explícitas
storage/             Cache e logs locais
tests/               Testes unitários sem banco externo
bin/                 Comandos pequenos do projeto
deploy/hostgator/     Manifesto e documentação do mirror de produção
```

## Estudar o projeto

Para compreender a arquitetura, seguir os fluxos reais e revisar segurança, testes, operação local e deploy, use o [Plano de estudo e revisão](docs/PLANO_DE_ESTUDO_E_REVISAO.md).

## Rotas

As rotas ficam em `routes/web.php`:

```php
$router->get('/users/{id}', [$userController, 'show']);
$router->post('/users', [$userController, 'store']);
```

GET e POST são demonstrados. PUT, PATCH e DELETE estão preparados por `_method` em um POST. A rota inexistente responde com página e status 404.

## Controllers e views

Controllers recebem a requisição, validam entradas, chamam serviços ou repositories quando necessários e escolhem uma `Response`. HTML extenso fica em `resources/views`; valores dinâmicos são impressos com `e()`.

```php
<h1><?= e($title) ?></h1>
```

O fluxo inicial demonstra `Router → HomeController → View → Layout`. O POST em `/example` demonstra Request, Validator, CSRF, flash e redirect HTTP sem salvar dados.

## Repositories e services

Crie um repository por assunto do domínio e mantenha SQL nele, por exemplo `UserRepository::findById()`. Não crie acesso genérico a tabelas arbitrárias. Services são opcionais e só devem existir quando coordenarem regra de negócio ou integração real. Models podem ser objetos simples; este projeto não inclui ORM.

## Banco e migrations

`App\Core\Database` cria PDO sob demanda com exceptions, fetch associativo, prepared statements nativos e `utf8mb4`. As credenciais vêm exclusivamente do ambiente.

Adicione SQL versionado a `database/migrations/` com nomes ordenáveis. `composer migrate` cria a tabela de controle e executa cada arquivo ainda não registrado uma única vez. Não há migration de negócio no template. Faça backup e teste alterações de schema antes de produção.

## Segurança

- secrets somente no `.env`, nunca no Git;
- prepared statements e proibição de concatenar input em SQL;
- escape HTML com `e()`;
- CSRF baseado em token de sessão e `hash_equals()`;
- cookies HttpOnly, SameSite=Lax, modo estrito e Secure configurável;
- mensagens genéricas em produção e detalhes nos logs;
- uploads ignorados e execução de PHP bloqueada em `public/uploads`;
- redirects HTTP, validação no backend e ações mutáveis fora de GET.

Leia a política completa em [docs/SECURITY.md](docs/SECURITY.md).

## Produção

Use pelo menos:

```env
APP_ENV=production
APP_DEBUG=false
SESSION_SECURE=true
```

Instale dependências com `composer install --no-dev --classmap-authoritative`, conceda escrita apenas a `storage/` e diretórios de upload necessários, configure HTTPS e aponte o Document Root para `public/`.

## Apache, cPanel e hospedagem compartilhada

No cenário ideal, configure o domínio/subdomínio para a pasta `public/`. Mantenha `app`, `bootstrap`, `config`, `database`, `storage`, `tests` e `vendor` fora do diretório servido.

Quando o provedor não permitir alterar o Document Root, mantenha o projeto fora de `public_html`, copie apenas o conteúdo de `public/` para `public_html` e ajuste os caminhos do front controller para a localização privada real. Não copie `.env`, `vendor` ou código interno para uma área publicamente acessível. Confirme com o provedor o caminho absoluto, suporte a PHP 8.2+, Composer, `mod_rewrite` e regras `.htaccess`; não adicione handlers PHP específicos do cPanel ao template.

O comando `composer deploy:hostgator` gera um mirror de atualização com `vendor` de produção. Ele nunca inclui `.env`, `.htaccess`, configurações PHP do servidor, uploads, logs ou cache, e nunca envia ou apaga arquivos remotos. As regras Apache de exemplo devem ser mescladas manualmente na primeira instalação. Migrations também permanecem uma etapa separada.

## Rotas incluídas

- `GET /` — página inicial e formulário demonstrativo
- `POST /example` — pipeline protegido, sem persistência
- `GET /health` — `{"status":"ok"}` sem detalhes internos
- demais caminhos — página 404 com status correto

Veja também [arquitetura](docs/ARCHITECTURE.md), [segurança](docs/SECURITY.md) e [desenvolvimento local](docs/LOCAL_DEVELOPMENT.md).
