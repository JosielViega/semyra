# Semyra Desktop

## Publicação IPTV

Quando o runtime oficial do GStreamer também oferece `whipsink`, o Desktop anuncia `media.whip` e `livekit.publish`. O canal continua resolvido exclusivamente no catálogo SQLite local; a aplicação web envia somente o identificador opaco do canal. O Desktop provisiona um endpoint WHIP efêmero com sua Host Session, publica áudio/vídeo no LiveKit e remove o ingress ao parar ou trocar a autorização. URLs e credenciais da fonte IPTV nunca atravessam o JavaScript ou o backend PHP.

A reprodução local (`iptv.play`) permanece disponível mesmo quando `whipsink` não está instalado.

Fundação Windows do Semyra: uma janela WPF hospeda o aplicativo web existente em Microsoft Edge WebView2. Login, cadastro e sessão continuam sendo os fluxos web normais; o perfil persistente do WebView fica em `%LOCALAPPDATA%\Semyra\WebView2`. Na página de login, o handshake Desktop pré-marca “Manter conectado” sem impedir que o usuário desmarque. A persistência usa somente o cookie HttpOnly emitido pelo servidor; o aplicativo nativo não lê nem armazena tokens de autenticação.

## Requisitos

- Windows x64;
- .NET 10 SDK para compilar;
- Microsoft Edge WebView2 Runtime para executar;
- GStreamer nativo Windows MSVC x64 com os plugins exigidos para habilitar `iptv.play`;
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

O recurso **Minha IPTV** exige uma conta Semyra autenticada, mas continua gratuito e sem qualquer regra Premium. O backend fornece ao Desktop somente um `desktop_profile_id` opaco, aleatório e estável; ele não é credencial nem autoriza operações. Cada ativação cria ainda um `accountContextId` efêmero que protege os comandos nativos contra páginas e `channelId` antigos.

Cada conta usa um banco fisicamente separado em `%LOCALAPPDATA%\Semyra\Data\Profiles\<desktop_profile_id>\semyra.db`. URLs, arquivos, credenciais, canais e catálogo permanecem somente nesse computador e continuam protegidos por DPAPI `CurrentUser`; nada disso é sincronizado com o servidor. O banco legado sem dono em `%LOCALAPPDATA%\Semyra\Data\semyra.db` não é lido, apagado nem associado automaticamente a qualquer conta.

Logout ou troca de conta invalida imediatamente o contexto, para mídia/publicação, limpa a autorização e desmonta catálogo e Media Engine. Reativar a mesma conta restaura seu catálogo local; a mesma conta em outro computador possui catálogo independente.

Fontes M3U por URL HTTP(S) ou arquivo podem ser cadastradas na tela **Minha IPTV**, visível somente quando a sessão web está autenticada e a WebView confirma o contexto da conta e as capabilities nativas. A localização da fonte e cada URL de stream são protegidas com Windows DPAPI no escopo `CurrentUser`; respostas à WebView nunca contêm esses valores.

O refresh lê a playlist em streaming e só substitui o catálogo da fonte em uma transação SQLite válida. A interface consulta grupos e canais com busca e paginação limitada, sem carregar o catálogo inteiro.

## Media Engine local

O teste de canal envia somente o `channelId` para o native. O Host Engine resolve e descriptografa a URL internamente, conecta ao provider com `HttpClient`, valida os sync bytes MPEG-TS e alimenta o stdin do GStreamer. A URL privada nunca entra em argv, ambiente, snapshot, evento ou resposta para a WebView.

A pipeline preserva H.264 (`h264parse` → `rtph264pay`) e converte AAC para Opus 48 kHz estéreo a 96 kbps antes dos RTP payloaders. O teste local termina em `fakesink`; a publicação da sala conecta os mesmos ramos ao `whipsink`. Interrupções inesperadas usam até três reconexões com backoff de 1, 2 e 5 segundos, reprovisionando o ingress; stop manual e troca de canal cancelam a sessão anterior e removem processo e ingress.

O Media Engine atual valida somente entrada MPEG-TS contínua. VODs MP4, HLS, MKV e HEVC não são suportados nesta etapa e devem ser tratados por uma evolução separada do produto.

O aplicativo procura primeiro `runtime/gstreamer/bin` ao lado do executável. Em Debug, `SEMYRA_GSTREAMER_HOME` e a instalação oficial MSVC x64 podem ser usados para desenvolvimento. O empacotamento definitivo do runtime será tratado na etapa de distribuição. `iptv.play` só é anunciado depois que `gst-launch`, `gst-inspect` e todos os elementos obrigatórios forem validados.

## Fronteira futura

O Desktop possui a camada WebView/UI, uma ponte `postMessage` allowlisted na versão 1 e um Host Engine local in-process. Sem conta ativa ele anuncia somente `host.status` e `host.authorize`; as capabilities IPTV aparecem apenas depois de `account.activate`. Seu snapshot não inclui `desktop_profile_id`, identidade, dados da máquina nem credenciais.

Para a publicação de mídia, o backend emite uma Host Session curta de `media.publish`, ligada à sala, instance, revision e owner atuais. O validator fica somente como hash no banco; o token bruto chega ao Host Engine apenas em memória, é substituído por uma autorização nova e é apagado por `clear` ou `Stop()`. Segredos globais do bridge worker nunca são enviados ao cliente.

Ainda não existem Premium, player visual local, Xtream, EPG, gravação ou distribuição do runtime GStreamer. O Host Engine pode ser separado em outro processo no futuro se isolamento operacional se tornar necessário.
