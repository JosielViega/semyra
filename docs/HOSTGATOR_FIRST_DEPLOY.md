# Primeira publicação do Semyra na HostGator

Este guia prepara uma primeira instalação controlada. Ele não autoriza upload, alteração remota, criação de banco ou execução de migration pelo Codex. Substitua todos os placeholders e nunca coloque segredos neste arquivo ou no Git.

## Responsabilidades

### Preparação local

- confirmar a revisão Git aprovada e uma árvore limpa;
- executar testes, lint e validações de segurança;
- gerar `deploy/hostgator/mirror/` com `composer deploy:hostgator`;
- revisar o mirror e `deploy/hostgator/build-info.json`;
- produzir um backup ou pacote local identificado pelo commit, sem `.env` nem dados privados.

### Ações manuais no cPanel

- confirmar PHP, extensões, banco, HTTPS, Document Root e limites da conta;
- criar banco, usuário e `.env` exclusivos de produção;
- preparar diretórios privados e públicos;
- enviar o conteúdo revisado do mirror;
- mesclar as regras Apache necessárias sem substituir configurações existentes;
- executar migrations conscientemente e somente depois de backup.

### Validações posteriores

- testar health check, autenticação, salas, comunicação e integrações;
- verificar logs sanitizados, permissões, headers, MIME e conectividade;
- configurar o Desktop para o domínio HTTPS somente depois de o site estar estável.

## 1. Pré-requisitos

### PHP e Composer

- PHP 8.2 ou superior, conforme `composer.json`;
- Composer 2 apenas na máquina de build. O mirror já inclui `vendor/`, portanto Composer não precisa ficar exposto no servidor web;
- extensões exigidas pelo lockfile: `pdo`, `pdo_mysql`, `json`, `iconv` e `pcre`;
- extensões/funções exigidas pela aplicação: `session`, `filter`, `hash` e suporte a senhas (`password_hash`/`password_verify`);
- `openssl` e `curl` recomendados para TLS e conectividade HTTP com LiveKit; a conectividade real deve ser testada no ambiente publicado;
- `mbstring` é recomendada para contagem Unicode consistente, embora exista fallback no chat;
- `fileinfo` será obrigatória antes de habilitar uploads de usuário. A primeira versão não recebe uploads.

Validação local das dependências de produção:

```bash
composer check-platform-reqs --no-dev
composer validate --strict
```

### MySQL

- MySQL 8.0 ou superior, com InnoDB, `utf8mb4` e foreign keys habilitadas;
- o desenvolvimento e a regressão usam MySQL 8.4;
- MariaDB não foi certificado nesta rodada. Se a conta fornecer MariaDB, valide todas as migrations em um banco vazio equivalente antes da publicação;
- a conexão da aplicação fixa `time_zone = '+00:00'`; confirme que o usuário do banco pode executar essa configuração de sessão;
- o fuso visível da aplicação continua definido por `APP_TIMEZONE`.

### Sistema de arquivos e limites

- escrita somente em `storage/logs`, `storage/cache` e, quando realmente utilizado, `public/uploads`;
- nenhum diretório gravável deve permitir execução de PHP;
- comece com `memory_limit` de pelo menos 128 MiB e `max_execution_time` de pelo menos 30 s, confirmando o consumo real no cPanel;
- a aplicação atual não recebe arquivos. Mantenha limites conservadores para `upload_max_filesize` e `post_max_size`;
- sessões PHP precisam estar habilitadas e ter armazenamento gravável e estável;
- Apache precisa oferecer `mod_rewrite` ou mecanismo equivalente.

## 2. Checklist cPanel antes do upload

- [ ] PHP selecionado é 8.2 ou superior.
- [ ] `pdo_mysql`, `json`, `iconv`, `pcre`, `session`, `filter` e `hash` estão ativos.
- [ ] `openssl` e `curl` estão ativos.
- [ ] MySQL é 8.0+ ou a compatibilidade MariaDB foi testada separadamente.
- [ ] Domínio e Document Root reais foram identificados sem presumir `public_html`.
- [ ] Certificado HTTPS válido está ativo e o redirecionamento HTTP para HTTPS foi planejado.
- [ ] Rewrite de URLs foi confirmado.
- [ ] MIME de `.js` é `application/javascript` ou `text/javascript`.
- [ ] `storage/logs` e `storage/cache` podem ser gravados pelo PHP e não são públicos.
- [ ] Banco e usuário exclusivos foram criados com privilégios apenas nesse banco.
- [ ] Há backup restaurável antes de qualquer migration.
- [ ] Saída HTTPS do PHP para a API LiveKit está permitida.
- [ ] Navegadores conseguem acessar o endpoint LiveKit `wss://` e o transporte WebRTC/TURN exigido pelo provedor.

### Checklist manual por fase

#### Fase A — Verificações

- [ ] Confirmar domínio, certificado HTTPS e Document Root configurável.
- [ ] Confirmar PHP 8.2+, extensões requeridas e caminho do PHP CLI.
- [ ] Confirmar MySQL 8.0+ ou validar separadamente a compatibilidade MariaDB.
- [ ] Confirmar Terminal/SSH ou Cron Jobs e limites de CPU, memória, processos, disco e banco.

#### Fase B — Instalação

- [ ] Criar banco e usuário MySQL exclusivos, com privilégios somente no banco do Semyra.
- [ ] Criar o diretório privado da aplicação e fazer o Document Root apontar para `public/`.
- [ ] Enviar somente o ZIP revisado e preservar a estrutura relativa do pacote.
- [ ] Criar o `.env` privado manualmente e configurar permissões mínimas de leitura/escrita.

#### Fase C — Banco

- [ ] Criar e verificar um backup restaurável antes da primeira migration.
- [ ] Executar `php bin/migrate.php` pelo PHP CLI no diretório privado.
- [ ] Confirmar tabelas, foreign keys e os 20 nomes completos na tabela `migrations`.
- [ ] Executar o runner novamente e confirmar `No pending migrations.`.

#### Fase D — Verificação

- [ ] Validar `/health`, cadastro, login, logout, Remember Me e criação/entrada em sala.
- [ ] Validar chat de texto, tokens LiveKit e recepção de mídia.
- [ ] Validar chat de voz com microfone inicialmente desligado.
- [ ] Executar manualmente o gate acústico do modo Padrão, RNNoise e fallback.

## 3. Estrutura segura de diretórios

### Opção preferida

Mantenha o pacote em um diretório privado e aponte o Document Root do domínio diretamente para `public/`:

```text
<APP_PRIVATE_ROOT>/
    app/
    bootstrap/
    config/
    database/
    resources/
    routes/
    storage/
    vendor/
    composer.json
    composer.lock
    public/              <- Document Root
        index.php
        assets/
        uploads/
```

Somente `public/` pode ser servido pela internet. `.env`, `vendor/`, migrations, logs e código PHP interno permanecem privados.

### Quando o cPanel não permite apontar o Document Root para `public/`

1. mantenha todo o aplicativo em `<APP_PRIVATE_ROOT>` fora da área servida;
2. copie `public/assets/` para `<DOCUMENT_ROOT>/assets/`;
3. crie manualmente `<DOCUMENT_ROOT>/index.php` como um front controller mínimo que carregue `<APP_PRIVATE_ROOT>/public/index.php`;
4. use um caminho absoluto definido pelo usuário no servidor, nunca um caminho inventado ou versionado;
5. mescle as regras de rewrite em `<DOCUMENT_ROOT>/.htaccess`;
6. não copie `app/`, `config/`, `database/`, `storage/` ou `vendor/` para a pasta pública.

Exemplo conceitual do wrapper, a ser adaptado manualmente:

```php
<?php

declare(strict_types=1);

require '<APP_PRIVATE_ROOT>/public/index.php';
```

O arquivo real do servidor não deve ser versionado com o caminho da conta.

## 4. HTTPS, Apache, headers e MIME

- redirecione HTTP para HTTPS usando a ferramenta do cPanel ou regras já adotadas pela conta;
- mescle, não substitua, `deploy/hostgator/server-config-examples/htaccess.rules.example`;
- se uploads forem usados futuramente, mescle também `uploads-security.rules.example` no diretório correspondente;
- mantenha listagem de diretórios desabilitada;
- configure ao menos `X-Content-Type-Options: nosniff`, `Referrer-Policy` e proteção contra framing;
- planeje HSTS somente depois de validar HTTPS em todos os subdomínios afetados;
- uma CSP deve liberar apenas as origens realmente usadas. `connect-src` precisa contemplar o `wss://` do LiveKit; não copie uma CSP genérica que quebre WebSocket, WebRTC ou AudioWorklet.

Depois do upload, confirme os módulos RNNoise:

```bash
curl -I https://<DOMINIO>/assets/vendor/rnnoise/rnnoise-worklet.js
curl -I https://<DOMINIO>/assets/vendor/rnnoise/rnnoise-sync.js
curl -I https://<DOMINIO>/assets/vendor/rnnoise/rnnoise-frame-buffer.js
```

As respostas devem ser `200`, usar MIME JavaScript aceito e não redirecionar para HTML/login. O worklet usa imports ES Modules relativos e depende de HTTPS. O WASM está embutido em `rnnoise-sync.js`; não há arquivo `.wasm` nem CDN em runtime.

## 5. Configuração de produção

Crie `.env` somente no diretório privado do servidor. Use valores novos e nunca copie automaticamente o arquivo de desenvolvimento:

```env
APP_NAME="Semyra"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<DOMINIO>
APP_TIMEZONE=America/Sao_Paulo

DB_HOST=<HOST_MYSQL>
DB_PORT=3306
DB_DATABASE=<BANCO>
DB_USERNAME=<USUARIO>
DB_PASSWORD=<SEGREDO>
DB_CHARSET=utf8mb4

SESSION_NAME=<NOME_EXCLUSIVO>
SESSION_SECURE=true
AUTH_REMEMBER_DAYS=30
DESKTOP_HOST_SESSION_TTL_SECONDS=600

LIVEKIT_ENABLED=true
LIVEKIT_URL=wss://<LIVEKIT_HOST>
LIVEKIT_API_KEY=<SEGREDO_DO_SERVIDOR>
LIVEKIT_API_SECRET=<SEGREDO_DO_SERVIDOR>
LIVEKIT_NAMESPACE=<NAMESPACE_EXCLUSIVO_DE_PRODUCAO>

MEDIA_BRIDGE_ENABLED=false
MEDIA_BRIDGE_WORKER_SECRET=
MEDIA_BRIDGE_LEASE_SECONDS=20
MEDIA_BRIDGE_MAX_FAILURES=3
```

Regras:

- `APP_DEBUG` deve permanecer `false`;
- `APP_URL` deve corresponder à origem HTTPS real;
- cookies de sessão e Remember Me ficam `HttpOnly`, `SameSite=Lax` e `Secure` sob HTTPS;
- API Secret LiveKit e segredos de worker ficam somente no backend;
- o entitlement Premium atual é o campo `users.is_premium`, `false` por padrão. Não existe chave global de Premium no `.env`;
- deixe o Media Bridge desabilitado se nenhum worker externo administrado estiver configurado;
- logs ficam em `storage/logs`, fora do Document Root, com acesso restrito e rotação definida pelo operador.

## 6. Geração e auditoria do mirror

Na revisão Git aprovada:

```bash
composer check
composer deploy:hostgator
git diff --check
```

O manifesto correto é `deploy/hostgator/config/deploy.php`. O builder usa allowlist, instala dependências com `--no-dev --classmap-authoritative` e não faz upload nem executa migrations.

Confirme no mirror:

- `app/`, `bin/migrate.php`, `bootstrap/`, `config/`, `database/migrations/`, `public/`, `resources/`, `routes/`, `vendor/`, `composer.json` e `composer.lock`;
- assets de YouTube, IPTV, Screen, chat de texto, chat de voz e LiveKit;
- `room-noise-suppression.js`, os três arquivos RNNoise e as duas licenças;
- migration `2026_10_29_000019_create_room_messages.sql`;
- commit e contagem em `deploy/hostgator/build-info.json`.

Confirme a ausência de:

- `.env*`, `.git/`, `.github/`, `tests/`, `docs/`, `desktop/` e qualquer ferramenta de `bin/` diferente de `bin/migrate.php`;
- logs, cache, uploads privados, bancos, playlists, runtimes MPV/GStreamer e artefatos de build;
- certificados, chaves, tokens, cookies ou credenciais.

## 7. Banco vazio e migrations

O pacote inclui somente `bin/migrate.php` do diretório de ferramentas. Ele deve permanecer no diretório privado da aplicação, fora do Document Root. O runner resolve a raiz a partir da própria localização e carrega `vendor/autoload.php`, o `.env` privado, `config/database.php` e `database/migrations/` dessa mesma raiz.

Os arquivos são executados em ordem lexicográfica pelo nome completo. Existem dois identificadores numéricos `000017`, mas os nomes completos e datas são distintos e a ordem permanece determinística. Não renomeie migrations publicadas.

Procedimento seguro:

1. crie um banco vazio com `utf8mb4`;
2. registre versão do servidor, charset e collation;
3. tire um backup vazio ou estado inicial restaurável;
4. configure o `.env` privado antes de usar a CLI;
5. no diretório privado da aplicação, execute `php bin/migrate.php` uma única vez;
6. confirme 20 registros na tabela `migrations`, incluindo `2026_10_29_000019_create_room_messages.sql`;
7. execute novamente e espere `No pending migrations.`;
8. valide foreign keys, índices e tabelas sem inserir dados de desenvolvimento.

As DDLs MySQL podem causar commit implícito. O runner registra o arquivo somente depois de executar todo o SQL e não oferece rollback automático. Se uma migration falhar parcialmente, pare: não edite a tabela `migrations` para forçar sucesso. Restaure o backup, corrija a causa em ambiente de ensaio e repita a partir de um estado conhecido.

Nunca importe o banco local completo. Ele pode conter contas, sessões, tokens, mensagens, credenciais locais ou outros dados de teste.

### Conta sem Terminal ou SSH

Não crie um endpoint HTTP para migrations e não coloque credenciais na linha de comando. Verifique primeiro se o cPanel oferece **Cron Jobs** com PHP CLI. Se oferecer, crie temporariamente uma tarefa que entre no diretório privado e execute o mesmo runner:

```text
cd <APP_PRIVATE_ROOT> && /usr/local/bin/php bin/migrate.php
```

Confirme com a HostGator o caminho real do PHP CLI. Execute a tarefa uma vez, preserve a saída operacional sem segredos, confira a tabela `migrations` e remova a tarefa imediatamente. Se Cron Jobs ou PHP CLI não estiverem disponíveis, solicite ao suporte da hospedagem a execução assistida do comando no diretório privado. Não substitua esse fluxo por importação parcial no phpMyAdmin: isso pode aplicar DDL sem registrar corretamente o histórico. Se a hospedagem não oferecer nenhuma execução CLI segura, a publicação fica bloqueada até existir um procedimento que preserve a tabela `migrations`.

## 8. Chat de texto e carga de polling

O chat consulta a cada 3 segundos quando o painel está ativo e a cada 9 segundos em segundo plano. A presença consulta a cada 5 segundos sem transmissão e a cada 1 segundo durante uma transmissão.

Estimativa de requests por segundo, com todos os participantes mantendo o chat ativo:

| Participantes | Sem transmissão | Com transmissão |
|---:|---:|---:|
| 2 | 1,07 req/s | 2,67 req/s |
| 5 | 2,67 req/s | 6,67 req/s |
| 10 | 5,33 req/s | 13,33 req/s |
| 20 | 10,67 req/s | 26,67 req/s |

Os valores somam somente chat e presença; abertura de página, envio, tokens e reconexões são adicionais. Monitore CPU, processos PHP, conexões MySQL e limites da hospedagem antes de ampliar o acesso.

O rate limit atual do chat é por sessão/participante (6 mensagens em 10 s e bloqueio de repetição imediata). Ele é adequado como contenção inicial em teste controlado, mas não é defesa distribuída contra um agente que cria várias sessões. Login e cadastro também não possuem throttling persistente. Antes de exposição ampla, aplique proteção adicional no servidor/WAF e planeje rate limiting compartilhado.

Mensagens de salas persistentes permanecem até a sala ser removida; salas temporárias são removidas com cascade. Defina retenção e capacidade antes de crescimento público.

## 9. LiveKit

Configuração esperada:

- `LIVEKIT_ENABLED=true` somente com URL `wss://`, API key, API secret e namespace exclusivos;
- tokens têm TTL de 600 segundos;
- viewer: entra e assina, mas não publica mídia nem dados;
- voz: entra, assina e publica exclusivamente source `microphone`;
- IPTV/Screen: publicação exige conta Premium, owner atual, Host Session curta, sala, instância e revisão cercadas;
- Free e Guest válidos continuam podendo assistir e conversar;
- credenciais administrativas nunca chegam ao browser ou Desktop.

Conectividade a validar depois do deploy:

1. PHP/HostGator para API HTTPS LiveKit para Ingress;
2. navegador/WebView2 para `wss://` LiveKit;
3. navegador e LiveKit via ICE/UDP/TCP/TURN para WebRTC;
4. Desktop para endpoint WHIP efêmero;
5. resolução DNS e certificados em cada caminho.

Uma home HTTPS acessível não comprova nenhum desses quatro caminhos de mídia.

## 10. RNNoise

- o processamento acontece no dispositivo do participante;
- o runtime Jitsi/RNNoise é local, com licenças Apache-2.0 e BSD-3-Clause preservadas;
- não há CDN nem upload de áudio para um serviço de redução de ruído;
- falha de suporte ou carregamento faz fallback para o modo Padrão;
- HTTPS, MIME JavaScript e imports relativos do AudioWorklet precisam funcionar;
- qualidade acústica, CPU real e compatibilidade entre dispositivos continuam sendo gate humano.

## 11. Semyra Desktop no domínio público

Release exige uma origem HTTPS explícita:

```powershell
$env:SEMYRA_DESKTOP_URL = 'https://<DOMINIO>/'
```

Antes de distribuir:

- valide certificado e URL final;
- confirme que navegação e mensagens nativas aceitam somente o mesmo scheme, host e port;
- confirme login, Remember Me, cookies e permissão de microfone no perfil WebView2;
- teste Account Context, IPTV local, publicação Premium, Screen, LiveKit e RNNoise;
- mantenha DevTools desabilitado em Release;
- não inclua `.env`, credenciais IPTV, SQLite local ou segredos LiveKit no pacote;
- não gere instalador final até concluir o gate da etapa posterior.

## 12. Upload manual

1. gere e revise o mirror local;
2. produza um arquivo de backup do estado remoto atual, se existir;
3. envie arquivos privados para `<APP_PRIVATE_ROOT>`;
4. envie somente `public/` para o Document Root escolhido;
5. crie diretórios graváveis vazios e permissões mínimas;
6. crie o `.env` manualmente fora do Document Root;
7. mescle regras Apache existentes;
8. não apague arquivos remotos com base apenas na ausência deles no mirror;
9. aplique migrations em uma janela separada e com backup.

## 13. Health check e gate posterior ao deploy

### Backend e web

- [ ] `GET /health` retorna `200` sem detalhes internos.
- [ ] Home, 404 e erro genérico não expõem caminhos ou stack trace.
- [ ] Cadastro, login, logout e Remember Me funcionam sob HTTPS.
- [ ] Cookies possuem `Secure`, `HttpOnly` e `SameSite=Lax`.
- [ ] Sala temporária e persistente podem ser criadas e reabertas.
- [ ] YouTube inicia e sincroniza.
- [ ] Chat de texto envia, pagina, atualiza e preserva XSS como texto.

### LiveKit, voz e RNNoise

- [ ] Viewer Free ou Guest recebe IPTV/Screen sem permissão de publicação.
- [ ] Conta Premium autorizada publica; Free ou Guest não publica.
- [ ] Voz conecta com microfone inicialmente desligado.
- [ ] Token de voz não publica camera, screen ou data.
- [ ] Modo RNNoise carrega localmente e o fallback funciona.
- [ ] Gate acústico entre dispositivos físicos foi executado pelo usuário.

### Desktop

- [ ] Release carrega somente o domínio HTTPS autorizado.
- [ ] Origem externa não controla o DesktopBridge.
- [ ] IPTV local continua isolada por conta e dispositivo.
- [ ] Screen e publicação WHIP passam pelo gate manual.

## 14. Backup e rollback

Antes da mudança:

- exporte o banco com schema e dados, proteja o arquivo fora do Document Root e teste a restauração;
- preserve uma cópia do pacote de arquivos atualmente publicado;
- registre commit, build-info, horário e migrations pendentes;
- não armazene o backup no Git.

Rollback de arquivos:

1. coloque o site em modo de manutenção pelo mecanismo da hospedagem, se disponível;
2. restaure o pacote anterior;
3. preserve `.env`, uploads e logs operacionais;
4. limpe somente caches explicitamente identificados;
5. repita o health check.

Rollback de banco:

- as migrations não possuem `down` automático;
- restaure o backup compatível com a versão de código anterior;
- não tente desfazer DDL manualmente sob tráfego;
- se não houver restauração segura, mantenha a publicação interrompida e investigue antes de prosseguir.

## 15. Riscos conhecidos da primeira publicação

### P0 — bloqueadores

- O fuso da sessão MySQL não era fixado, podendo alterar a expiração de Host Sessions em hospedagens fora de UTC. Corrigido: toda conexão inicializa `time_zone = '+00:00'`.
- Nenhum outro P0 de código foi comprovado na auditoria local. A publicação ainda deve parar se Document Root, HTTPS, extensões, banco, migrations ou segredos não passarem no cPanel.

### P1 — recomendado antes de exposição ampla

- confirmar compatibilidade do banco real; MariaDB não foi certificado;
- adicionar throttling compartilhado ou WAF para autenticação e chat;
- aplicar headers globais de segurança e uma CSP compatível com LiveKit e AudioWorklet;
- validar a saída HTTPS do PHP e todos os caminhos WebSocket, WebRTC e WHIP;
- validar backup, restauração e falha parcial de migration;
- concluir gate acústico de voz e RNNoise entre dispositivos.

### P2 — melhorias futuras

- retenção administrativa de mensagens;
- observação de carga e eventual adaptação do polling;
- automação de backup, rollback e checks externos;
- investigação separada do áudio de sistema do Screen Publisher;
- empacotamento e instalador final do Desktop.

Este documento termina na preparação. Upload, configuração remota e migrations de produção pertencem à etapa manual posterior.
