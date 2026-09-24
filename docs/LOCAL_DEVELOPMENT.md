# Desenvolvimento local

> Cada projeto deve utilizar uma porta local própria.

## Configuração automática

Depois de `composer install`, execute:

```bash
composer setup
```

Se `.env` não existir, ele será criado de `.env.example`. Se existir, seu conteúdo será preservado. O setup identifica o projeto pelo caminho absoluto normalizado e consulta o registro persistente de portas antes de escolher uma porta entre 8010 e 8999.

Uma porta só pode ser atribuída quando satisfaz as duas condições:

1. não está reservada para outro projeto conhecido;
2. não possui listener ativo no sistema.

Assim, projetos desligados continuam protegidos contra colisões:

```text
Projeto A → 8010
Projeto B → 8011
Projeto C → 8012
```

O comando nunca encerra processos. Ele altera somente `APP_PORT` e, quando representa localhost, `APP_URL`; URLs externas e todas as demais variáveis são preservadas.

## Registro local

O registro fica no perfil do usuário:

```text
Windows:       %USERPROFILE%\.modeloPHP\ports.json
Linux/macOS:   ~/.modeloPHP/ports.json
```

Ele contém apenas a versão do formato e associações entre caminhos absolutos normalizados e portas:

```json
{
  "version": 1,
  "projects": {
    "C:/Projects/example": {
      "port": 8010
    }
  }
}
```

O arquivo é local, não pertence ao Git, não deve ser copiado entre máquinas e não contém `.env`, credenciais ou tokens. Escritas usam lock exclusivo para impedir que dois setups concorrentes corrompam ou reutilizem a mesma reserva. JSON inválido gera erro e é preservado para reparo manual.

Durante o setup, uma entrada antiga só é removida quando o projeto não existe e seu diretório pai está acessível, tornando a ausência clara. Projetos movidos são tratados como novos projetos.

## Configuração manual

Copie `.env.example` para `.env` e escolha a porta:

```env
APP_URL=http://localhost:8010
APP_PORT=8010
```

A porta `8010` é somente o início da busca. Uma edição manual do `.env` não atualiza a reserva; execute `composer setup` depois.

## Verificar disponibilidade

No Windows PowerShell:

```powershell
Get-NetTCPConnection -State Listen | Where-Object LocalPort -eq 8010
```

No Linux/macOS, uma opção comum é:

```bash
lsof -iTCP:8010 -sTCP:LISTEN
```

Saída indicando um listener significa que a porta já pertence a outro processo. Não encerre esse processo: escolha uma nova porta livre para este projeto e atualize `APP_PORT` e `APP_URL` juntos.

## Iniciar

```bash
composer serve
```

O comando verifica novamente a disponibilidade antes de executar o servidor PHP em `127.0.0.1`. Se registro e `.env` divergirem, ele interrompe com uma recomendação para executar `composer setup`. Se outro processo ocupar a porta, falha sem tentar encerrá-lo.

## Consultar ou liberar a reserva

```bash
composer port:status
composer port:release
```

`port:status` mostra somente caminho, reserva, `APP_PORT` e disponibilidade do projeto atual; não lista os demais projetos. `port:release` remove somente a associação atual, não altera `.env` e não mata processos. Rode `composer setup` para reservar novamente.

## Vários projetos

Execute `composer setup` em cada cópia. Um projeto já registrado preserva sua porta enquanto ela não possui listener; se estiver ocupada, o setup seleciona e registra outra de forma conservadora. Apache permanece a referência para produção: o registro e `APP_PORT` não participam de decisões do deploy HostGator.

## MySQL local com Docker Compose

O Docker é usado apenas para o MySQL 8.4 local. PHP, Composer e o servidor de desenvolvimento continuam rodando diretamente no host. A produção HostGator não depende de Docker.

Crie um `.env.docker` local, que é ignorado pelo Git, sem reutilizar credenciais de produção:

```env
SEMYRA_DB_PORT=3306
SEMYRA_DB_DATABASE=semyra
SEMYRA_DB_USERNAME=semyra_app
SEMYRA_DB_PASSWORD=<senha-local-forte>
SEMYRA_DB_ROOT_PASSWORD=<outra-senha-local-forte>
```

Configure o `.env` do PHP com a mesma porta, database, usuário e senha da aplicação:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=semyra
DB_USERNAME=semyra_app
DB_PASSWORD=<mesma-senha-local-da-aplicacao>
DB_CHARSET=utf8mb4
```

Se a porta 3306 já estiver ocupada, escolha outra porta livre em `SEMYRA_DB_PORT` e `DB_PORT`. A porta interna do container permanece 3306. Não encerre processos existentes para liberar a porta.

Comandos básicos:

```bash
docker compose --env-file .env.docker up -d mysql
docker compose --env-file .env.docker ps
docker compose --env-file .env.docker stop mysql
docker compose --env-file .env.docker start mysql
docker compose --env-file .env.docker down
```

`down` remove o container e a network, mas preserva o volume nomeado. Não use `down -v` se quiser manter os dados. Depois que o health check indicar que o MySQL está saudável, execute:

```bash
composer migrate
composer serve
```
