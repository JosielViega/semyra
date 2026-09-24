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
