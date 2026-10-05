# Semyra Desktop

Fundação Windows do Semyra: uma janela WPF hospeda o aplicativo web existente em Microsoft Edge WebView2. Login, cadastro e sessão continuam sendo os fluxos web normais; o perfil persistente do WebView fica em `%LOCALAPPDATA%\Semyra\WebView2`. Na página de login, o handshake Desktop pré-marca “Manter conectado” sem impedir que o usuário desmarque. A persistência usa somente o cookie HttpOnly emitido pelo servidor; o aplicativo nativo não lê nem armazena tokens de autenticação.

## Requisitos

- Windows x64;
- .NET 10 SDK para compilar;
- Microsoft Edge WebView2 Runtime para executar;
- uma instância acessível do Semyra web.

O projeto usa `net10.0-windows`, WPF e `Microsoft.Web.WebView2` 1.0.4258.31. Não é self-contained e não produz instalador nesta etapa.

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

## Fronteira futura

O Desktop possui hoje somente a camada WebView/UI e uma ponte `postMessage` mínima, versão 1, para ping/pong. O futuro Host Engine será um componente local separado para mídia hospedada pelo cliente. IPTV, M3U, Xtream, GStreamer, WHIP e integração de mídia com LiveKit não fazem parte desta fundação.
