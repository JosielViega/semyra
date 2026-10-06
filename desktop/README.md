# Semyra Desktop

Fundação Windows do Semyra: uma janela WPF hospeda o aplicativo web existente em Microsoft Edge WebView2. Login, cadastro e sessão continuam sendo os fluxos web normais; o perfil persistente do WebView fica em `%LOCALAPPDATA%\Semyra\WebView2`. Na página de login, o handshake Desktop pré-marca “Manter conectado” sem impedir que o usuário desmarque. A persistência usa somente o cookie HttpOnly emitido pelo servidor; o aplicativo nativo não lê nem armazena tokens de autenticação.

## Requisitos

- Windows x64;
- .NET 10 SDK para compilar;
- Microsoft Edge WebView2 Runtime para executar;
- uma instância acessível do Semyra web.

O projeto usa `net10.0-windows`, WPF, `Microsoft.Web.WebView2` 1.0.4258.31 e `Microsoft.Data.Sqlite` 10.0.12. Não é self-contained e não produz instalador nesta etapa.

## Build e execução

```powershell
dotnet restore desktop\Semyra.Desktop.sln
dotnet build desktop\Semyra.Desktop.sln --configuration Debug
dotnet run --project desktop\src\Semyra.Desktop\Semyra.Desktop.csproj
```

Em Debug, `SEMYRA_DESKTOP_URL` ausente usa `http://localhost:8010/`. É possível apontar para outra origem:

```powershell
$env:SEMYRA_DESKTOP_URL = 'https://semyra.example/'
dotnet run --project desktop\src\Semyra.Desktop\Semyra.Desktop.csproj
```

Release não possui URL implícita e exige `SEMYRA_DESKTOP_URL` HTTPS. HTTP é aceito somente para `localhost` e `127.0.0.1` em Debug. Navegações externas abrem no navegador padrão.

## Catálogo IPTV local

Fontes M3U por URL HTTP(S) ou arquivo podem ser cadastradas na tela **Fontes IPTV**, visível somente quando a WebView confirma as capabilities nativas. Metadados e canais ficam em `%LOCALAPPDATA%\Semyra\Data\semyra.db`, fora do perfil WebView2. A localização da fonte e cada URL de stream são protegidas com Windows DPAPI no escopo `CurrentUser`; respostas à WebView nunca contêm esses valores.

O refresh lê a playlist em streaming e só substitui o catálogo da fonte em uma transação SQLite válida. A interface consulta grupos e canais com busca e paginação limitada, sem carregar o catálogo inteiro. Nesta etapa não existe reprodução, publicação, EPG, Xtream ou pipeline de mídia.

## Fronteira futura

O Desktop possui a camada WebView/UI, uma ponte `postMessage` allowlisted na versão 1 e um Host Engine local in-process. Ele nasce parado, acompanha o lifecycle da janela e anuncia somente `host.status`, `host.authorize`, `iptv.sources` e `iptv.catalog`. Seu snapshot não inclui identidade, dados da máquina nem credenciais.

Para uma futura publicação de mídia, o backend pode emitir uma Host Session curta de `media.publish`, ligada à sala, instance, revision e owner atuais. O validator fica somente como hash no banco; o token bruto chega ao Host Engine apenas em memória, é substituído por uma autorização nova e é apagado por `clear` ou `Stop()`. Segredos globais do bridge worker nunca são enviados ao cliente.

Ainda não existe processamento de mídia: Xtream, GStreamer, WHIP e publicação LiveKit permanecem fora desta fundação. O Host Engine pode ser separado em outro processo no futuro se isolamento operacional se tornar necessário.
