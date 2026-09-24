# Plano de estudo e revisão do modeloPHP

> Objetivo: permitir que o desenvolvedor compreenda este projeto de ponta a ponta, consiga explicar suas decisões técnicas e revise criticamente o código, inclusive código produzido por IA.

Este é o guia permanente para compreender, revisar e evoluir o `modeloPHP`. Ele descreve o projeto que existe hoje: toda afirmação sobre comportamento deve ser confirmável no código, nos testes ou na execução dos comandos indicados.

O guia é deliberadamente ativo. Em vez de apenas apresentar arquivos, ele propõe perguntas, exercícios, evidências e critérios de domínio. Deve ser atualizado sempre que uma mudança alterar arquitetura, fluxos, segurança, operação, testes ou implantação.

> Regra central: documentação descreve a intenção; código mostra a implementação; testes registram parte do comportamento esperado; execução confirma o comportamento no ambiente real. Quando houver divergência, investigue as quatro fontes antes de concluir.

## 1. Visão executiva

O `modeloPHP` é um ponto de partida reutilizável para aplicações PHP 8.2 ou superior, sem framework web. Ele organiza uma aplicação HTTP com front controller, roteamento, controllers, views, sessão, proteção CSRF, validação, tratamento de erros, log e acesso PDO a MySQL. Também oferece ferramentas de linha de comando para configuração local, reserva de portas, migrations, validação e construção de um espelho de produção para HostGator.

O projeto usa Composer para dependências, autoload PSR-4 e automação. Em produção, depende de `vlucas/phpdotenv`; em desenvolvimento, usa PHPUnit. O fluxo HTTP entra por `public/index.php`, carrega `bootstrap/app.php`, registra `routes/web.php`, cria uma `Request`, despacha o `Router` e envia uma `Response`.

Hoje o repositório demonstra infraestrutura e um fluxo de formulário, não um domínio completo. `app/Models`, `app/Repositories` e `app/Services` estão preparadas, mas não contêm classes de domínio. Também não há autenticação, autorização, usuários, pagamentos, integração externa ou upload implementados. A proteção Apache de `public/uploads` é uma defesa preparada, não uma funcionalidade de upload.

### Tempo de estudo sugerido

| Bloco | Tempo |
|---|---:|
| Preparação e visão geral | 1–1,5 h |
| Entrada HTTP, bootstrap e configuração | 2–3 h |
| Router, Request e Response | 2–3 h |
| Controllers, views, validação, sessão e CSRF | 2–3 h |
| Banco e migrations | 1–2 h |
| Erros, logs e segurança | 1–2 h |
| Portas e servidor local | 1,5–2,5 h |
| Testes, CI e espelho HostGator | 2–3 h |
| Revisão transversal por fluxo | 2–3 h |
| **Total realista da primeira passagem** | **14,5–23 h** |

Leitura passiva pode ser mais rápida. Domínio exige executar fluxos, explicar decisões sem consultar o código e registrar lacunas verificáveis.

## Como usar este guia

Para cada fase:

1. leia somente os arquivos indicados;
2. descreva o fluxo com suas palavras antes de executar;
3. execute os exercícios sem alterar comportamento;
4. compare a previsão com a saída real;
5. registre dúvidas como fatos a confirmar, não defeitos presumidos;
6. conclua a fase apenas após produzir as evidências pedidas.

Use anotações temporárias, issue ou caderno. Os artefatos recomendados adiante só devem entrar no repositório quando revisados e úteis de forma permanente.

## 2. Mapa do repositório

| Caminho | Função atual | Entra no runtime? | Prioridade de estudo |
|---|---|---|---|
| `app/` | Código PHP da aplicação e espaços de domínio | Sim, parcialmente | Alta |
| `app/Controllers` | Início, formulário e saúde | Sim, HTTP | Alta |
| `app/Core` | HTTP, view, sessão, CSRF, banco, erros e logs | Sim | Alta |
| `app/Helpers` | Funções globais `env()` e `e()` | Sim | Alta |
| `app/Validation` | Validador por regras | Sim | Alta |
| `app/Models` | Espaço reservado, sem classes atuais | Não ainda | Baixa no template |
| `app/Repositories` | Espaço reservado para persistência | Não ainda | Média para derivados |
| `app/Services` | Espaço reservado para casos de uso | Não ainda | Média para derivados |
| `bootstrap/` | Composição e inicialização em `app.php` | Sim | Alta |
| `config` | Configuração de aplicação e banco | Sim | Alta |
| `routes/` | Rotas e fallback em `web.php` | Sim | Alta |
| `database/` | Migrations e espaço de seeds | CLI quando aplicável | Média |
| `public/` | Document root, front controller, assets e defesa de uploads | Sim | Alta |
| `public/index.php` | Front controller | Sim | Alta |
| `public/.htaccess` | Rewrite e proteção Apache | Sim no Apache | Alta para produção |
| `public/assets` | CSS e JavaScript | Sim, estático | Baixa |
| `public/uploads/.htaccess` | Bloqueio de scripts | Sim no Apache | Média; defesa preparada |
| `resources/` | Views, layout e páginas | Sim | Alta |
| `database/migrations` | SQL versionado; hoje só instruções | CLI quando houver SQL | Média |
| `database/seeds` | Local reservado; hoje só instruções | Não automaticamente | Baixa |
| `storage/` | Estado mutável local de logs e espaço de cache | Parcialmente | Alta para operação |
| `storage/logs` | Logs diários | Sim em falhas | Alta para operação |
| `storage/cache` | Espaço preparado, sem uso atual | Não | Baixa |
| `bin` | Setup, serve, portas, migrate, lint e build | CLI | Alta |
| `bin/lib` | Implementações dos comandos | CLI | Alta |
| `tests` | Testes PHPUnit | Não em produção | Alta |
| `.github/workflows/ci.yml` | Integração contínua | Sim no CI | Alta |
| `deploy/hostgator` | Manifesto, exemplos e instruções | Build/deploy manual | Alta para produção |
| `docs` | Arquitetura, segurança, operação e estudo | Não | Média |
| `composer.json` | Dependências, autoload e comandos | Install/build/CLI | Alta |
| `composer.lock` | Dependências reproduzíveis | Install/build | Alta |
| `.env.example` | Contrato de ambiente sem segredos | Template, não runtime | Alta |

## 3. Modelo mental das camadas

### Fluxo HTTP

```text
Apache ou servidor embutido
  -> public/index.php
  -> bootstrap/app.php
  -> routes/web.php
  -> Request
  -> Router::dispatch()
  -> Controller ou fallback
  -> View/Response
  -> Response::send()
```

No Apache, `public/.htaccess` entrega arquivos/diretórios reais e reescreve outros pedidos para `index.php`. No servidor embutido, `bin/server-router.php` retorna `false` para arquivos existentes e inclui o front controller nos demais casos.

`bootstrap/app.php` carrega Composer, lê `.env` com Dotenv, carrega configurações, instala `Logger` e `ErrorHandler`, valida ambiente, ajusta timezone e cookies e cria os componentes. Retorna um mapa associativo usado pelo front controller e scripts. A composição é manual; não há container de DI.

A instância de `Database` nasce no bootstrap, mas PDO só conecta em `connection()`. Nenhuma rota atual consulta banco.

### Limites atuais

- `Router`, `Request` e `Response` formam a fronteira HTTP.
- Controllers coordenam entrada, validação, sessão e resposta.
- `View` resolve página e layout.
- `Session` mantém estado; flash é consumido uma vez.
- `Csrf` gera e compara token ligado à sessão.
- `Validator` protege o formulário demonstrativo.
- `Database` encapsula PDO; não existem repositories concretos.
- `Logger` e `ErrorHandler` tratam falhas inesperadas.
- `bin` contém aplicações CLI separadas que reutilizam partes do projeto.

### Entrada e bootstrap

Estude `public/index.php` e `bootstrap/app.php` para Front Controller, Composer, PSR-4, configuração, composição manual e ciclo da requisição.

### Transporte HTTP

Estude `Router`, `Request` e `Response` para URI, métodos, parâmetros, status, headers, redirects, JSON, fallback 404 e method override.

### Apresentação

Estude controllers, `View`, layouts e páginas para MVC sem framework, constructor injection, output buffering, responsabilidades e escaping.

### Persistência

Estude `Database`, `database/migrations` e o espaço de repositories. PDO e migrations existem; repository de domínio, tabela de negócio e transaction boundary ainda não.

### Segurança

Estude `Session`, `Csrf`, `Validator`, `ErrorHandler` e `Logger` em conjunto. Autenticação e autorização não existem no template atual.

### Ferramentas de desenvolvimento

Estude `composer setup`, `composer serve`, `Port`, `PortRegistry`, `port:status` e `port:release` para ambiente, sockets, filesystem, lock e idempotência.

### Deploy

Estude `deploy/hostgator/`, `HostgatorMirrorBuilder`, Apache e `.htaccess` para separar código, configuração, estado e artefato de produção.

## 4. Ordem detalhada de estudo

Esta é a seção central. As fases são cumulativas: leitura sem explicação e evidência não encerra uma fase.

### Fase 0 — Ambiente e linha de base

**Objetivo:** confirmar integridade antes de estudar comportamentos.

**Leia:** `README.md`, `composer.json`, `.env.example`, `docs/LOCAL_DEVELOPMENT.md`, `phpunit.xml` e `.gitignore`.

**Investigue:**

- PHP `^8.2`, `ext-pdo` e `ext-pdo_mysql`;
- `vlucas/phpdotenv` em produção e `phpunit/phpunit` em desenvolvimento;
- PSR-4 de `App\\` para `app/` e `Tests\\` para `tests/`;
- autoload por arquivo de `app/Helpers/functions.php`;
- diferença entre `composer install`, `setup`, `check` e `deploy:hostgator`.

**Exercícios:**

1. Rode `composer install` quando `vendor` não existir.
2. Rode `composer setup` e confirme criação ou preservação de `.env`.
3. Rode `composer port:status`, depois `composer serve`, e desenhe servidor PHP -> `public/` -> `index.php`.
4. Rode `composer check` e identifique validate, lint e testes.
5. Compare PHP local, requisito do projeto e PHP 8.2 do CI.

**Entregáveis:** matriz “requisito/local/CI/resultado”, saída de `composer check` e explicação do lockfile.

**Checklist:**

- [ ] Sei o que Composer instala e como PSR-4 funciona.
- [ ] Entendo por que `vendor` não entra no Git.
- [ ] Sei onde ficam `.env` e `~/.modeloPHP/ports.json`.
- [ ] Sei como uma porta é escolhida.
- [ ] Sei iniciar o projeto localmente.

**Domínio:** preparar clone novo e separar falha de ambiente, dependência e projeto.

### Fase 1 — Entrada HTTP e bootstrap

**Leia em ordem:** `public/index.php`, `bootstrap/app.php`, helpers, `config/app.php`, `config/database.php` e `.env.example`.

**Investigue:** ausência de `vendor/autoload.php`; `safeLoad()`; ambientes `local`, `testing` e `production`; debug, URL, timezone e cookie; detecção HTTPS; ordem de criação de Logger, ErrorHandler, Session, Csrf, Validator, Database, View, Router e Request; conexão lazy.

**Exercícios:**

1. Desenhe o mapa retornado pelo bootstrap com classe e dependências.
2. Localize usos de `env()` e respectivos padrões.
3. Compare tratamento de erro local e produção.
4. Em ambiente descartável, preveja e confirme `APP_ENV` inválido.

**Entregável:** diagrama que diferencie objeto criado de recurso externo aberto.

**Trace obrigatório:** `GET /` -> `public/index.php` -> bootstrap -> rotas -> Router -> HomeController -> View -> layout -> Response. Para cada dado, registre origem, validação, transformação, destino e escape.

**Domínio:** narrar tudo entre front controller, bootstrap, rotas e resposta.

### Fase 2 — Router, Request e Response

**Leia:** `Request.php`, `Router.php`, `Response.php`, `routes/web.php` e `tests/RouterTest.php`.

**Investigue:** normalização do caminho; query/body/server/files; override POST por `_method` limitado a PUT/PATCH/DELETE; cinco verbos; segmentos `{param}`; fallback; exigência de `Response`; HTML, JSON, redirect, status, headers e `send()`.

**Exercícios:**

1. Percorra `GET /`, `POST /example`, `GET /health` e rota inexistente.
2. Associe cada teste à condição protegida.
3. Confirme o comportamento quando o caminho só existe para outro método: hoje cai no fallback, sem 405 específico.
4. Explique por que o override é limitado.

**Entregável:** tabela método + caminho -> handler -> resposta -> status.

**Domínio:** prever handler e resposta de toda rota atual.

### Fase 3 — Controllers, views e formulário

**Leia:** os dois controllers, `View.php`, layout, páginas, CSS e JavaScript.

**Investigue:** dados de `index()`; campo CSRF e flashes; branches de `submitExample()`; Post/Redirect/Get; `realpath` e confinamento da view; buffer, layout e `EXTR_SKIP`; escape com `e()`; fragmentos internos `$content` e `$csrfField`; JSON de health.

**Exercícios:**

1. Envie mensagem válida, curta e vazia.
2. Observe o consumo único de flash após redirects.
3. Envie HTML como mensagem e confirme que o valor não é ecoado nem persistido pelo fluxo demonstrativo; depois localize nos testes a garantia de escape de texto dinâmico.
4. Confira corpo, status e Content-Type de `/health`.
5. Acesse rota inexistente com caracteres especiais e confira escape.
6. Adicione apenas mentalmente uma rota `/about` e liste todos os arquivos que precisariam ser alterados ou criados; não implemente.

**Entregável:** sequência request -> sessão -> redirect -> segunda request.

**Domínio:** explicar toda saída escapada e todo fragmento confiável.

### Fase 4 — Configuração

**Leia:** `.env.example`, configs, `ProjectSetup.php`, `LOCAL_DEVELOPMENT.md` e `SECURITY.md`.

**Investigue:** `APP_*`, `DB_*`, `SESSION_*`; coerção booleana; relação `APP_PORT`/`APP_URL`; preservação de `.env`; segredo local versus exemplo versionado; cookies HttpOnly, SameSite=Lax, Secure e modo estrito.

**Exercícios:** faça matriz variável/consumidor/padrão/sensibilidade; compare ambiente local sem registrar segredos; explique `APP_DEBUG=false` em produção.

**Entregável:** checklist de promoção de configuração.

**Domínio:** identificar origem e consumidor de cada configuração.

### Fase 5 — Banco e migrations

**Leia:** `Database.php`, `bin/migrate.php`, os READMEs de migrations/seeds e config de banco.

**Investigue:** DSN MySQL; charset validado e fallback `utf8mb4`; PDO com exceptions, fetch associativo e prepared statements nativos como base contra SQL injection; conexão lazy; transactions como limite a definir nos casos de uso futuros; erro externo genérico; tabela de controle; ordenação de SQL; registro após execução; inexistência de rollback; commits implícitos de DDL; inexistência atual de migration de negócio e executor de seeds.

**Exercícios:**

1. Nomeie conceitualmente duas migrations dependentes.
2. Em banco descartável e branch de estudo, aplique uma migration duas vezes.
3. Descreva recuperação de falha no meio de SQL.
4. Localize chamadas a `Database::connection()` e confirme se uma rota conecta.

**Entregável:** runbook backup/aplicar/verificar/recuperar.

**Domínio:** não confundir infraestrutura PDO com persistência de negócio.

**Ficha futura por tabela:** nome; responsabilidade; campos; chaves estrangeiras; índices; quem grava; quem lê; dados sensíveis; regras. Só preencha quando a tabela existir em um projeto derivado.

### Fase 6 — Sessão, CSRF, validação e confiança

**Leia:** `Session.php`, `Csrf.php`, `Validator.php`, `HomeController.php`, home view e testes de CSRF, Validator e helpers.

**Investigue:** sessão e flash; token hexadecimal de 32 bytes; `hash_equals`; CSRF antes da validação; regras `required`, `email`, `string`, `integer`, `min` e `max`; validação HTML versus servidor; escape XSS; ausência de autenticação/autorização.

**Exercícios:**

1. Associe cada input à origem e validação.
2. Envie token inválido e confirme 419.
3. Explique por que `minlength` não substitui backend.
4. Liste fronteiras de confiança nos quatro fluxos HTTP.

**Entregável:** tabela entrada/validação/autorização/escape/falha, usando “não aplicável” corretamente.

**Checklist:** consigo explicar o ciclo GET -> token -> POST -> verificação -> validação -> flash -> redirect -> GET; sei por que 419 é distinto de erro de validação; sei por que frontend não substitui backend.

**Domínio:** distinguir autenticação, autorização, CSRF, validação e escape.

### Fase 7 — Erros e logs

**Leia:** `ErrorHandler.php`, `Logger.php`, bootstrap e páginas error/404.

**Investigue:** erros convertidos em `ErrorException`; handler global; referência única; classe/arquivo/linha/trace no log; debug detalhado versus produção genérica; log diário; três caminhos distintos: fallback 404 usa view, CSRF inválido usa `pages/error.php` com 419 e exceção global gera HTML mínimo no handler com 500.

**Exercícios:** provoque exceção controlada em ambiente descartável; compare debug ligado/desligado; confira escrita em `storage/logs`; revise possível dado sensível antes de adicionar log.

**Entregável:** evento/status público/mensagem pública/conteúdo interno/arquivo.

**Domínio:** localizar falha pela referência sem expor trace em produção.

### Fase 8 — Servidor e registro de portas

**Leia em ordem:** `Port.php`, `PortRegistry.php`, `ProjectSetup.php`, scripts setup/serve/server-router/status/release e seus três conjuntos de testes.

**Investigue:** faixa 8010–8999; conexão + bind; `~/.modeloPHP/ports.json` por caminho absoluto; `flock` compartilhado/exclusivo; preservação de JSON corrompido; limpeza de caminhos ausentes; reserva estável; porta reservada versus listener; divergência `.env`/registro; release remove reserva sem alterar `.env` ou processo.

**Exercícios:**

1. Rode `composer port:status` antes/depois de setup.
2. Inicie `composer serve` e confira estáticos e rota.
3. Em clone descartável, confirme outra porta.
4. Explique por que não matar processo desconhecido.
5. Confirme no teste que JSON corrompido preserva seu hash.
6. Explique por que só verificar listener não basta, por que o registro fica fora do projeto, como o lock protege duas execuções e como a limpeza trata um caminho claramente ausente após mover/apagar um projeto.

**Entregável:** estados sem reserva/reservado livre/reservado ativo/divergente/corrompido.

**Domínio:** resolver conflito sem apagar registro global nem processo alheio.

**Trace obrigatório:** setup -> identifica o projeto por caminho -> abre registro com lock -> limpa caminhos claramente ausentes -> verifica reservas -> verifica listeners -> escolhe/preserva porta -> ajusta somente configuração local aplicável -> persiste reserva.

### Fase 9 — Testes

**Leia:** `phpunit.xml`, todos os testes, scripts Composer e `bin/lint.php`.

| Teste | Componente | O que prova | O que não prova |
|---|---|---|---|
| `RouterTest` | Router/Request | rota dinâmica, fallback e override | servidor/rewrite/controller real |
| `ValidatorTest` | Validator | sucesso e coleção de erros | formulário HTTP completo |
| `CsrfTest` | Csrf/Session | formato e comparação do token | cookie no navegador |
| `HelpersTest` | `e()` | escape HTML | todos os pontos de saída |
| `PortTest` | Port | socket real e busca sequencial | comando serve completo |
| `PortRegistryTest` | PortRegistry | reserva, conflito, corrupção, limpeza, normalização e release | concorrência entre máquinas |
| `ProjectSetupTest` | ProjectSetup | env, porta, preservação e idempotência | execução Composer completa |
| `HostgatorDeployManifestTest` | Manifesto | allowlist e proteções | servidor remoto |
| `HostgatorMirrorBuilderTest` | Builder | mirror e ausência de proibidos/dev packages | cópia/execução na HostGator |

**Limites:** não há navegador E2E, MySQL de teste, aplicação completa via HTTP nem testes específicos de controllers, health, Database, ErrorHandler, Logger, Session, View ou comandos CLI completos.

**Classificação atual:** a suíte é majoritariamente unitária/de componente; `PortTest` integra com socket local e os testes de setup/registry/builder integram filesystem e artefato. Os testes do manifesto funcionam como contrato do pacote. Não há E2E de navegador.

**Exercícios:** associe cada teste a uma regressão; marque cobertura por etapa dos fluxos; rode `test`, `lint` e `check`; proponha o próximo teste pelo maior risco.

**Entregável:** matriz risco/teste/lacuna/proposta.

**Domínio:** não usar “há testes” como “todo fluxo está coberto”.

### Fase 10 — Integração contínua

**Leia:** `.github/workflows/ci.yml` e os scripts de `composer.json`.

**Fluxo real:**

```text
push ou pull request
  -> Ubuntu + PHP 8.2 + Composer 2
  -> composer install
  -> composer deploy:hostgator
     -> composer check
     -> build-hostgator-mirror.php
```

**Investigue:** eventos push/pull request; checkout; runner Ubuntu; setup de PHP 8.2; Composer 2; install reproduzível; diferença entre CI e máquina local; e o fato de `deploy:hostgator` chamar `check` antes de validar o mirror.

**Exercícios:**

1. Relacione cada step do workflow ao comando local equivalente.
2. Explique por que passar localmente não garante que o CI passará e vice-versa.
3. Siga `git push` -> GitHub Actions -> install -> deploy -> check/build -> resultado.
4. Confirme que o CI não recebe `.env` nem conecta ao MySQL.

**Entregável:** tabela step/ambiente/entrada/saída/falha.

**Domínio:** interpretar uma falha do workflow e reproduzir localmente o comando correspondente.

### Fase 11 — Deploy HostGator

**Leia em ordem:** `deploy/hostgator/`, `deploy/hostgator/config/deploy.php`, `bin/build-hostgator-mirror.php`, `bin/lib/HostgatorMirrorBuilder.php`, README, exemplos do servidor e testes do deploy.

**Trace:** código-fonte -> `composer deploy:hostgator` -> `composer check` -> mirror -> inspeção -> cópia manual -> servidor. O comando termina no mirror; não envia nem apaga arquivos remotos.

**Investigue:** build; allowlist; código versus configuração; secrets; vendor `--no-dev --classmap-authoritative`; arquivos obrigatórios; pacotes dev proibidos; `.htaccess`; env; uploads; logs; migrations; `build-info.json` fora do mirror; configuração manual do servidor.

**Exercícios:**

1. Rode o build e inspecione sem publicar.
2. Justifique cada item da allowlist.
3. Confirme ausência de `.env`, PHPUnit e estado mutável.
4. Liste “arquivos do código / ambiente / servidor / dados persistentes” e explique por que têm ciclos diferentes.
5. Simule remoção de arquivo antigo e o risco de cópia manual.
6. Compare document root `public/` com a alternativa da hospedagem.

**Entregável:** checklist backup/build/inspeção/cópia/config/migration/smoke/recuperação.

**Domínio:** distinguir build local, configuração do servidor, dados persistentes e implantação remota.

### Consolidação após as fases

**Exercícios:** faça os oito exemplos de revisão por fluxo; aplique as quatro passagens de revisão com IA; classifique os achados pelo padrão deste guia; escolha uma melhoria pequena com evidência, risco, teste e docs; valide se pertence ao modelo ou ao derivado.

**Entregável:** relatório reproduzível e proposta incremental.

**Domínio:** outra pessoa reproduz os achados sem interpretação oral.

## Roteiro de revisão por fluxo

### Template

| Campo | Pergunta |
|---|---|
| Ator | Quem inicia? |
| Entrada | Método, caminho, comando, argumento ou arquivo? |
| Confiança | Que dado não é confiável? |
| Orquestração | Que arquivo decide o próximo passo? |
| Validação | Que formato e regras se aplicam? |
| Autenticação | A identidade é necessária e verificada? |
| Autorização | Ela pode agir sobre o recurso? |
| Classes utilizadas | Que classes participam e com qual papel? |
| Banco | Há conexão, consulta, tabela ou transação? |
| Arquivos | Que arquivos são lidos, escritos ou servidos? |
| Estado | Sessão, flash ou filesystem? |
| Persistência | PDO, tabela, arquivo ou registro? |
| Serviços externos | Rede, processo, socket, API ou hospedagem? |
| Saída | Corpo, header, redirect, status ou arquivo? |
| Status HTTP | Qual status é esperado, quando aplicável? |
| Falhas | Quais caminhos de falha existem? |
| Logs | O que é registrado, onde e com que sensibilidade? |
| Teste existente | Que parte é automatizada? |
| Lacunas | O que depende de verificação ou decisão? |
| Minha conclusão | O que foi comprovado e o que segue como hipótese? |

Não invente autenticação/autorização. Use “não aplicável” ou “não implementado”.

### `GET /`

- **Ator/entrada:** navegador, GET `/`.
- **Orquestração:** front controller, Router e `HomeController::index()`.
- **Confiança:** sem body; configuração e sessão mantêm seus contratos.
- **Validação/identidade:** sem formulário, identidade ou recurso protegido.
- **Estado:** consome flashes e gera/reutiliza CSRF na sessão.
- **Persistência:** sem banco ou integração.
- **Saída:** home no layout, status 200.
- **Falha:** exceção vai ao ErrorHandler e gera 500.
- **Cobertura:** Router, CSRF e escape têm testes; request HTTP, controller e view integrados não.

### `POST /example`

- **Entrada:** `_token` e `message`, ambos vindos do cliente.
- **Orquestração:** `HomeController::submitExample()`.
- **Validação:** CSRF primeiro; mensagem `required|string|min:2|max:120`.
- **Identidade:** autenticação e autorização não existem/não são exigidas nesta demonstração.
- **Estado:** lê token e grava erros de validação ou sucesso em flash; o formulário atual não preserva o valor enviado.
- **Persistência:** nenhuma.
- **Saída:** 419 no token inválido; 302 para `/` em erro de validação ou sucesso; GET seguinte consome flash.
- **Cobertura:** componentes testados, mas sem integração controller/sessão/redirect.

### `GET /health`

- **Ator:** monitor ou navegador.
- **Entrada/identidade:** sem dado relevante e sem controle de identidade.
- **Orquestração:** `HealthController::index()`.
- **Persistência:** não consulta banco; comprova liveness HTTP, não saúde MySQL.
- **Saída:** JSON `{"status":"ok"}`, Content-Type JSON UTF-8 e 200.
- **Lacuna:** sem teste específico; explicitar contrato antes de ampliá-lo.

### Fallback 404

- **Entrada:** caminho sem rota e sem estático; o caminho é não confiável.
- **Orquestração:** fallback de `routes/web.php`.
- **Saída:** `pages/404.php` no layout, 404, caminho escapado.
- **Persistência:** nenhuma.
- **Cobertura:** seleção do fallback no Router; não a view/rewrite integrados.

### `composer setup`

- **Ator/entrada:** desenvolvedor, diretório, envs e registro global.
- **Orquestração:** `bin/setup.php` usa ProjectSetup, PortRegistry e Port; depois dump-autoload.
- **Confiança:** filesystem/socket pode estar ausente, divergente ou corrompido.
- **Estado:** pode criar `.env`, preserva existente e reserva caminho absoluto em `~/.modeloPHP/ports.json`.
- **Efeito:** consulta sockets e grava fora do repositório.
- **Falha:** corrupção, I/O ou falta de porta são explícitas.
- **Cobertura:** classes centrais testadas; não subprocesso Composer completo.

### `composer serve`

- **Entrada:** `.env`, diretório e registro.
- **Orquestração:** `bin/serve.php` verifica reserva/divergência/listener e inicia `php -S` com `public` e server-router.
- **Efeito:** processo e socket local.
- **Falha:** divergência ou porta indisponível encerra sem matar terceiros.
- **Cobertura:** algoritmos e socket testados; navegação completa é smoke manual.

### `composer migrate`

- **Entrada:** configuração e SQL versionado. Credenciais/banco são externos; SQL é código revisado, não input do usuário.
- **Orquestração:** script cria controle, ordena e executa pendentes.
- **Persistência:** MySQL e tabela de migrations.
- **Falha:** exceção interrompe; sem rollback automático.
- **Lacuna:** sem migration real ou suíte MySQL; produção exige backup/manual.

### `composer deploy:hostgator`

- **Entrada:** working tree, lockfile e manifesto.
- **Orquestração:** `check`, script e `HostgatorMirrorBuilder`.
- **Estado:** recria só o mirror configurado e grava build-info fora dele.
- **Efeito:** Composer pode baixar pacotes; não acessa HostGator.
- **Saída:** árvore mínima com vendor de produção e autoload autoritativo.
- **Falha:** item protegido, obrigatório ausente, pacote dev ou Composer interrompem.
- **Cobertura:** manifesto/builder e comando no CI; cópia, Apache, migration e smoke remoto são manuais.

## Método de revisão de código produzido por IA

Não peça revisão genérica do repositório inteiro. Delimite fluxo, arquivos, testes, saída e passagem.

### Passagem A — Correção funcional

Pergunte se o contrato funciona com entradas válidas, inválidas e bordas. Procure status incorreto, estado perdido, ordem errada, erro silencioso, idempotência e divergência docs/testes/execução. Exija arquivo/linha, cenário mínimo, atual, esperado e teste.

### Passagem B — Segurança

Siga dados não confiáveis da entrada à validação, autorização, persistência, log e saída. Procure XSS, CSRF, SQL injection, path traversal, segredo, upload executável, exceção exposta, permissão e confiança em proxy. Não aceite achado genérico: sem caminho real e reprodução, trate como investigação.

### Passagem C — Concorrência e integridade

Teste mentalmente dois processos, escrita parcial, porta ocupada, JSON corrompido, banco indisponível, mirror incompleto e deploy interrompido. Procure corrida, falta de lock, não idempotência, recuperação, diferença Windows/Linux/Apache, permissão e observabilidade. Aplique especialmente a PortRegistry, setup, migrations, logs e builder.

### Passagem D — Manutenibilidade e cobertura

Verifique se a mudança cabe no modelo, respeita fronteiras e tem prova proporcional ao risco. Procure duplicação, controller acoplado, contrato implícito, abstração prematura, cobertura ilusória e docs antigas.

Ao final, una duplicatas, descarte hipóteses sem reprodução, classifique prioridade e separe dúvida arquitetural de defeito.

> Não refatore enquanto ainda estiver tentando compreender o comportamento. Primeiro registre como funciona. Depois proponha mudanças.

### Prompt-base

```text
Revise somente o fluxo [nome] no modeloPHP.
Use os arquivos [lista], testes [lista] e execução [saída].
Faça a passagem [A/B/C/D]. Não invente classes, rotas ou requisitos.
Para cada achado, informe evidência, impacto, prioridade,
correção mínima e teste recomendado. Marque incertezas como investigação.
```

## Pontos de atenção para investigar

> Agenda de revisão, não veredito de defeito.

Estes itens não são bugs confirmados; são limites e decisões reais a investigar antes de crescer.

### Arquitetura

- Medir quando a composição manual justificaria factories/container.
- Criar Models/Repositories/Services só com caso real.
- Acompanhar crescimento de regras nos controllers.
- Decidir se 405 e header `Allow` pertencem ao Router.
- Manter explícito o contrato de `extract` e fragmentos HTML confiáveis.

### Segurança

- Definir renovação CSRF quando houver autenticação/troca de sessão.
- Revisar HTTPS/proxy antes de confiar em headers encaminhados.
- Projetar autenticação, autorização e regeneração de sessão somente com requisito.
- Preservar escape para dados de request, sessão, banco e serviços.
- Definir redaction antes de registrar payload/credencial.
- Se houver upload, validar MIME/tamanho/nome e preferir armazenamento não público.

### Banco

- Criar migration de negócio só com requisito.
- Definir transaction boundaries em services/repositories futuros.
- Planejar recuperação considerando DDL MySQL.
- Adicionar banco real/container quando existirem consultas.
- Decidir liveness simples versus readiness de banco.

### Operação local

- Validar macOS se entrar no suporte; CI atual cobre Linux e uso local conhecido cobre Windows.
- Avaliar recuperação de escrita interrompida sem aceitar JSON corrompido.
- Não confundir liberar reserva com encerrar servidor.
- Não tratar porta offline como livre se reservada por outro clone.

### Testes e CI

- Priorizar integração de formulário/sessão/flash por risco.
- Testar health se ganhar contrato operacional.
- Avaliar matriz PHP; CI atual usa somente 8.2.
- Testar migrations com MySQL quando houver migration real.
- Preservar build HostGator no CI.

### HostGator

- Cópia manual pode deixar arquivo obsoleto; incluir conferência.
- Não há rollback remoto; exigir backup/restauração.
- Adaptar exemplos Apache/PHP à conta e document root.
- Criar e dar permissões a estado mutável no destino.
- Manter migration separada do build e operada com backup.

## Critério para dizer “eu entendo este projeto”

- [ ] Preparar clone e executar `composer check`.
- [ ] Explicar Composer, PSR-4 e helpers.
- [ ] Narrar bootstrap e dependências na ordem.
- [ ] Seguir os quatro fluxos HTTP completos.
- [ ] Distinguir validação, escape, CSRF, autenticação e autorização.
- [ ] Explicar flash, token e cookies.
- [ ] Dizer quando PDO conecta.
- [ ] Operar migration com backup e sem prometer rollback.
- [ ] Localizar exceção por referência.
- [ ] Explicar reserva/ocupação/release de porta.
- [ ] Mapear testes a riscos e lacunas.
- [ ] Narrar CI e o `check` dentro do deploy.
- [ ] Construir/inspecionar mirror sem confundir com publicação.
- [ ] Separar implementado, preparado e futuro.
- [ ] Propor melhoria com evidência, teste e docs.

## Artefatos do estudo

- mapa do bootstrap;
- catálogo de rotas;
- sequência do formulário;
- matriz de ambiente;
- mapa de confiança;
- matriz teste/risco;
- estados das portas;
- DER quando existirem tabelas de negócio;
- fluxos de autenticação quando forem implementados;
- runbook de migration;
- checklist HostGator;
- matriz requisito -> código -> teste;
- lista de achados e plano de refatoração incremental;
- inventário implementado/preparado/não implementado;
- decisões arquiteturais;
- relatório das quatro passagens.

Artefato permanente deve indicar data, commit, fontes e comandos. Observação antiga não vira verdade atual.

## Classificação de achados

| Prioridade | Definição |
|---|---|
| **P0 Crítico** | Exploração ativa, perda ampla ou indisponibilidade total sem contenção |
| **P1 Alto** | Falha provável com grande impacto em segurança, dados ou operação |
| **P2 Médio** | Comportamento incorreto limitado, recuperação manual ou defesa incompleta |
| **P3 Baixo** | Clareza, manutenção, cobertura ou prevenção sem falha grave atual |
| **Investigação** | Hipótese plausível ainda sem evidência |

Prioridade mede impacto e probabilidade, não dificuldade. Teste ausente aumenta incerteza, mas não prova defeito.

Para cada achado, registre:

```text
Evidência:
Impacto:
Como reproduzir:
Correção proposta:
Teste contra regressão:
```

## Documentos de apoio

1. `README.md` — instalação, comandos e panorama;
2. este plano — aprendizagem e revisão;
3. `docs/ARCHITECTURE.md` — componentes e fluxos;
4. `docs/LOCAL_DEVELOPMENT.md` — ambiente e portas;
5. `docs/SECURITY.md` — postura e checklist;
6. `database/migrations/README.md` — convenção e riscos;
7. `deploy/hostgator/README.md` — build e implantação;
8. `composer.json` e workflow CI — comandos executáveis;
9. código, testes e execução do fluxo — prova final.

Em diagnóstico, siga rota -> controller -> componentes -> testes -> execução -> documentação operacional.

## Projetos derivados do modeloPHP

Ao usar **Use this template**, o novo repositório herdará este arquivo. Ele não deve ficar congelado descrevendo apenas o `modeloPHP`.

Derive com requisito concreto: entidades/tabelas, migrations/seeds, repositories, services, autenticação/autorização, validações, integrações, upload/retenção, jobs/e-mail, observabilidade, health/readiness e runbooks do produto. Se o derivado adicionar Auth, login, tabela `users`, `UserRepository`, e-mail, Mercado Pago, upload ou administração, crie fases e fluxos específicos somente depois que esses elementos existirem.

Antes de promover algo ao modelo base:

1. aparece em vários projetos ou só neste domínio?
2. há contrato pequeno e testável?
3. adiciona dependência/configuração a quem não usa?
4. funciona nos ambientes declarados?
5. há atualização segura para clones?

Não crie `User`, `AuthService` ou `UserRepository` fictícios para preencher pastas. Exemplos futuros devem ser rotulados como exemplos.

## Protocolo de atualização do documento

Atualize este guia no mesmo PR quando mudar rota, comando, bootstrap, dependência, autoload, configuração, sessão, CSRF, validação, escape, erro, log, banco, migration, domínio, integração, portas, CI, testes ou HostGator.

1. Identifique seções afetadas antes de codificar.
2. Atualize fatos, fluxos e exercícios após implementar.
3. Ajuste testes proporcionais ao risco.
4. Rode `composer check`; em deploy, `composer deploy:hostgator`.
5. Faça smoke test web/Apache/operacional quando não automatizado.
6. Atualize `CHANGELOG.md`.
7. Confirme caminhos, classes, comandos e contagens.

Afirmação não localizável deve virar hipótese, exemplo futuro ou investigação.

Não atualize o plano por pequena correção textual, ajuste visual trivial ou rename sem impacto conceitual. O histórico detalhado pertence ao Git e ao `CHANGELOG.md`; este arquivo ensina a arquitetura atual e orienta sua revisão.

## Regra para IA/Codex

> A IA pode acelerar navegação, explicação, hipóteses, testes e revisão, mas não substitui leitura do código, execução dos fluxos nem validação humana de segurança e operação.

> Quando uma IA realizar uma mudança estrutural ou implementar uma nova área importante do projeto, ela deve verificar se este plano de estudo ficou desatualizado e propor ou realizar a atualização correspondente.

A IA não deve transformar este documento em log de alterações.

- forneça commit, arquivos e fluxo delimitado;
- exija referências e reprodução;
- rejeite classes, rotas, tabelas e requisitos inventados;
- confira comandos em `composer.json` ou no script;
- execute testes e smoke tests;
- nunca envie `.env`, credenciais, logs sensíveis ou dados reais;
- trate segurança como hipótese até reprodução;
- preserve mudanças existentes e revise o diff;
- mantenha decisões de produto e risco sob responsabilidade humana.

## Documentação x código x testes x execução

> Documentação mostra o que deveria acontecer. Código mostra o que foi implementado. Testes mostram quais cenários foram verificados. Execução controlada mostra o que realmente acontece.

Quando documentação, código, testes e execução divergirem:

1. registre commit e ambiente;
2. reduza ao menor caso reproduzível;
3. determine a fonte do comportamento desejado;
4. corrija sem ampliar escopo;
5. adicione evidência automatizada conforme risco;
6. atualize guia e changelog.

O objetivo não é memorizar arquivos. É provar como o sistema funciona hoje, reconhecer o que ainda não faz e evoluí-lo sem romper garantias.
