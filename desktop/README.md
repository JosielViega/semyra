# Semyra Desktop

## Publicação IPTV

Quando o runtime oficial do GStreamer também oferece `whipsink`, o Desktop anuncia `media.whip` e `livekit.publish`. O canal continua resolvido exclusivamente no catálogo SQLite local; a aplicação web envia somente o identificador opaco do canal. O Desktop provisiona um endpoint WHIP efêmero com sua Host Session, publica áudio/vídeo no LiveKit e remove o ingress ao parar ou trocar a autorização. URLs e credenciais da fonte IPTV nunca atravessam o JavaScript ou o backend PHP.

A seleção IPTV da sala exige simultaneamente `iptv.catalog`, a capability nativa `livekit.publish` e o entitlement Premium validado pelo servidor. O Desktop apresenta separadamente o gate Premium e a indisponibilidade do runtime WHIP, sem forçar ou simular capabilities. Com o runtime disponível, a interface carrega fontes e canais Live sanitizados e, quando `livekit.publish-vod` também está disponível, oferece Filmes e Séries sem expor URLs ou credenciais.

Filmes e episódios Xtream também podem ser publicados por contas Premium quando o Desktop anuncia `livekit.publish-vod`. A WebView envia somente `kind` (`movie` ou `episode`) e `mediaId`; o Host Engine resolve a URL protegida no catálogo local. Um proxy HTTP efêmero limitado ao loopback entrega a mídia ao GStreamer por uma URL aleatória e opaca. O pipeline aceito nesta etapa é MP4/QuickTime com H.264 e AAC. O vídeo é decodificado e reencodado localmente por `x264enc` em constrained-baseline, 2,8 Mbps, zero B-frames, `key-int-max=48` e `tune=zerolatency`; o áudio AAC continua convertido para Opus. `clocksync` aplica os timestamps das duas branches ao mesmo GstClock antes dos payloaders RTP. Containers ou codecs incompatíveis falham com erro sanitizado, e EOF encerra a transmissão sem autoplay. TV ao vivo usa um pipeline separado, mantém o pacing MPEG-TS da fonte e normaliza o vídeo em constrained-baseline a 3,5 Mbps, sem `clocksync` adicional.

O player integrado (`iptv.local-view`) permanece independente de `whipsink`. Assistir localmente é gratuito, exige somente uma conta ativa no Desktop e não cria sala, Host Session, WHIP ou conexão LiveKit. Somente publicar IPTV em uma sala exige o entitlement Premium atual da conta; o backend valida essa regra antes de criar a transmissão, ao emitir a Host Session e novamente antes do ingress.

Fundação Windows do Semyra: uma janela WPF hospeda o aplicativo web existente em Microsoft Edge WebView2. Login, cadastro e sessão continuam sendo os fluxos web normais; o perfil persistente do WebView fica em `%LOCALAPPDATA%\Semyra\WebView2`. Na página de login, o handshake Desktop pré-marca “Manter conectado” sem impedir que o usuário desmarque. A persistência usa somente o cookie HttpOnly emitido pelo servidor; o aplicativo nativo não lê nem armazena tokens de autenticação.

## Requisitos

- Windows x64;
- .NET 10 SDK para compilar;
- Microsoft Edge WebView2 Runtime para executar;
- MPV x64 em `runtime/mpv/mpv.exe` ao lado do aplicativo para o player local;
- GStreamer nativo Windows MSVC x64 com `whipsink` e os plugins exigidos para publicação WHIP/LiveKit;
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

### Ambiente online HostGator

A origem oficial é configurada explicitamente por processo, sem alterar variáveis globais do Windows:

```powershell
$env:SEMYRA_DESKTOP_URL = 'https://semyra.jzlcode.com.br/'
$env:WEBVIEW2_USER_DATA_FOLDER = '<WORKTREE>\storage\cache\webview2-profile-13d-online'
$env:SEMYRA_MPV_HOME = '<RUNTIME_MPV>'
$env:SEMYRA_GSTREAMER_HOME = '<RUNTIME_GSTREAMER>'
```

O perfil WebView2 online é separado do perfil local; cookies, cache e sessão não são copiados entre ambientes. Essa pasta não altera o SQLite IPTV, que continua exclusivamente em `%LOCALAPPDATA%\Semyra\Data\Profiles\<desktop_profile_id>\semyra.db` e protegido por DPAPI `CurrentUser`.

Os launchers locais ignorados `storage/cache/start-13d-online.cmd` e `storage/cache/start-13d-local.cmd` preparam, respectivamente, a origem HTTPS e o ambiente local já validado. `start-13d-online.cmd --check-only` verifica URL, .NET, build, WebView2 e runtimes sem abrir o aplicativo, iniciar PHP ou iniciar MySQL. MPV ou GStreamer ausentes desabilitam apenas as capabilities nativas correspondentes; site, autenticação e chat permanecem disponíveis.

A política da WebView2 continua restrita ao mesmo scheme, host e port da URL configurada. Rotas internas permanecem na WebView2, destinos HTTP(S) externos seguem para o navegador padrão e mensagens nativas de outras origens são ignoradas.

## Catálogo IPTV local

O recurso **Minha IPTV** exige uma conta Semyra autenticada, mas continua gratuito e sem qualquer regra Premium. O backend fornece ao Desktop somente um `desktop_profile_id` opaco, aleatório e estável; ele não é credencial nem autoriza operações. Cada ativação cria ainda um `accountContextId` efêmero que protege os comandos nativos contra páginas e `channelId` antigos.

Cada conta usa um banco fisicamente separado em `%LOCALAPPDATA%\Semyra\Data\Profiles\<desktop_profile_id>\semyra.db`. URLs, arquivos, credenciais, canais e catálogo permanecem somente nesse computador e continuam protegidos por DPAPI `CurrentUser`; nada disso é sincronizado com o servidor. O banco legado sem dono em `%LOCALAPPDATA%\Semyra\Data\semyra.db` não é lido, apagado nem associado automaticamente a qualquer conta.

Logout ou troca de conta invalida imediatamente o contexto, para mídia/publicação, limpa a autorização e desmonta catálogo e Media Engine. Reativar a mesma conta restaura seu catálogo local; a mesma conta em outro computador possui catálogo independente.

Fontes M3U por URL HTTP(S), arquivo ou Xtream podem ser cadastradas na tela **Minha IPTV**, visível somente quando a sessão web está autenticada e a WebView confirma o contexto da conta e as capabilities nativas. Na fonte Xtream, servidor, usuário e senha formam uma configuração JSON versionada inteiramente protegida com Windows DPAPI no escopo `CurrentUser`; cada URL de stream também é protegida. Respostas à WebView nunca contêm localização, credenciais ou URLs reproduzíveis.

O refresh M3U lê a playlist em streaming. O refresh Live do Xtream autentica em `player_api.php`, sincroniza `get_live_categories` e `get_live_streams` e constrói a URL Live apenas no native. Uma operação separada sincroniza globalmente `get_vod_categories`, `get_vod_streams`, `get_series_categories` e `get_series`; somente ao abrir uma série o native consulta uma vez `get_series_info` e substitui atomicamente o cache de episódios daquela série. Falhas preservam o último catálogo válido. A interface consulta os dados com paginação limitada a 100 itens, sem carregar catálogos inteiros.

Favoritos e Recentes também permanecem somente nesse SQLite local, separados por conta e por fonte e disponíveis sem Premium. A versão 6 do schema preserva M3U e Live, mantém tabelas distintas para categorias VOD, filmes, séries e episódios, progresso VOD por identidade estável e ocultações não destrutivas de “Continuar assistindo”. M3U usa a identidade lógica derivada do `tvg-id`; Xtream usa IDs externos cercados por fonte e tipo. Filmes usam `xtream:movie:{stream_id}` e episódios usam `xtream:episode:{series_id}:{episode_id}`, portanto refresh do catálogo ou dos episódios não perde posição. A contagem VOD representa IDs únicos realmente persistidos, não operações repetidas do payload. URLs `/movie/...` e `/series/...` são construídas com credenciais descriptografadas apenas no momento de uso. No MPV, Recentes Live só são gravados depois que a posição real começa a avançar. Nada é sincronizado com o backend ou outro dispositivo.

Um item recente pode ser removido diretamente, e a limpeza apaga o histórico inteiro somente da fonte atual, sempre cercada pela conta e fonte ativas. **Todos os canais**, Favoritos e Recentes pertencem exclusivamente à TV ao vivo. Filmes e Séries exibem **Continuar assistindo** somente quando há progresso válido, limitado a 20 itens por fonte. Remover um card dessa rail grava somente uma ocultação por identidade estável; posição, conclusão e histórico episódico permanecem, e qualquer atividade posterior ao instante da ocultação faz o item reaparecer. Séries carregam `get_series_info` ao abrir o detalhe, agrupam temporadas e recuperam o estado não iniciado/em andamento/assistido em uma consulta batch. Filme e episódio usam `semyra.desktop.iptv.vod.view-start` somente com tipo e ID local; o native resolve o resume e aplica o seek exato por IPC depois que o MPV confirma VOD seekable. Episódios expõem anterior/próximo, incluindo transição entre temporadas, sem enviar lista, URL ou stable key ao JavaScript. Um futuro “Pular abertura” poderá consultar IntroDB somente quando um `imdb_id` confiável já vier do provider; o Semyra não chama TMDB/IMDb para enriquecer o catálogo.

## Media Engine de publicação

O início de publicação Live envia somente o `channelId` para o native. O Host Engine resolve e descriptografa a URL internamente, conecta ao provider com `HttpClient`, valida os sync bytes MPEG-TS e alimenta o stdin do GStreamer. Para VOD, envia-se apenas o tipo e o ID local; a URL privada permanece na memória nativa e o processo GStreamer recebe somente o endereço loopback efêmero. A URL privada nunca entra em argv, ambiente, snapshot, evento ou resposta para a WebView.

A publicação Live decodifica o H.264 do provider e o normaliza por `videoconvert`/`x264enc` em constrained-baseline, zero B-frames, `key-int-max=48` e `tune=zerolatency` antes do `rtph264pay`. A resolução e o frame rate da fonte são preservados e nenhum `clocksync` de VOD é inserido. AAC é convertido para Opus 48 kHz estéreo a 96 kbps antes de conectar os ramos ao `whipsink`. Interrupções inesperadas usam até três reconexões com backoff de 1, 2 e 5 segundos, reprovisionando o ingress; stop manual e troca de canal cancelam a sessão anterior e removem processo e ingress.

O caminho Live do Media Engine continua validando somente entrada MPEG-TS contínua e agora também exige decoder H.264, `videoconvert` e `x264enc`; sem a cadeia de normalização, `livekit.publish` não é anunciado. O caminho VOD dedicado aceita MP4/QuickTime seekable com H.264 e AAC e exige ainda `gst-discoverer`, `souphttpsrc`, `qtdemux` e `clocksync`. HLS, MKV, HEVC e outras combinações continuam fora do escopo e falham de forma sanitizada.

A publicação VOD usa o processo isolado `Semyra.MediaHost`, que hospeda o pipeline persistente pela API nativa mínima do GStreamer. O Desktop envia por JSON Lines em stdin somente a URL loopback, o endpoint WHIP e um identificador efêmero de sessão; nenhum desses valores entra em argv. `pause`, `play`, `seek`, `snapshot` e `stop` são allowlisted, e snapshots retornam apenas estado, posição, duração, seekability e session ID. Os comandos são cercados no Host Engine pela conta, Host Session, sala, instância e revisão atuais. O owner de IPTV VOD recebe Play/Pause, ±30 s e barra de progresso com posição/duração consultadas do pipeline real; viewers preservam apenas volume, mute e fullscreen locais. A publicação Live continua no processo `gst-launch-1.0` aprovado e não oferece pause/seek.

No VOD compartilhado, conteúdo e publicação são dois `GstPipeline` ligados por `intervideosink/src` e `interaudiosink/src`. Pause aguarda o pipeline de conteúdo alcançar `PAUSED`, congelando a posição canônica, enquanto o publicador permanece `PLAYING`: vídeo repete o último frame, áudio produz silêncio e os clocks RTP continuam monotônicos no mesmo SSRC. Play aguarda `PLAYING` real do conteúdo; comandos duplicados são idempotentes. Seek permanece um caso cercado de republicação, encerra o WHIP anterior em `NULL`, usa seek preciso e somente confirma depois de posição e RTP de áudio/vídeo válidos.

A fronteira `interaudio` usa caps idênticas e concretas nos dois pipelines (`S16LE`, 48 kHz, dois canais, interleaved). `interaudiosrc` mantém 200 ms de buffer, reporta 40 ms de latência e produz períodos mínimos de 10 ms. O encoder Opus permanece vivo em Pause/Play; silêncio e conteúdo ocupam a mesma timeline contínua, sem nova source, mixer, fade ou renegociação de formato.

### Player integrado

Em **Minha IPTV**, o fluxo local é direto: `provider → MPV`. O Host Engine resolve o `channelId`, descriptografa a URL somente em memória e a envia ao IPC privado do sidecar por `loadfile`; ela não entra em argv, JavaScript, snapshot, evento ou log. O MPV detecta container e codecs, e seu estado real determina se a mídia é Live ou VOD. Nenhum probe por extensão, nome ou grupo antecede o início.

O processo MPV permanece aquecido enquanto a conta está ativa. Trocas usam `loadfile replace`; stop interrompe a mídia e esconde o HWND sem matar o sidecar. Logout, troca de conta e fechamento invalidam a sessão, escondem o host e encerram o processo com timeout curto e kill-tree de contingência. O HWND é dimensionado pelo retângulo de interseção entre o stage e a viewport e fica oculto quando a interseção é vazia, impedindo que cubra catálogo, busca ou configurações.

Cada nova carga também limpa o estado transitório, força `pause=false` antes de `loadfile replace` e cerca os eventos pelo `playlist_entry_id`. O HWND só aparece depois de `playback-restart` e da confirmação de uma faixa de vídeo; falha, fim, stop ou troca o escondem imediatamente. `pause` observado no MPV é a autoridade do botão, enquanto `paused-for-cache` representa buffering sem inverter a intenção do usuário. Assim, uma falha intermediária não contamina a próxima tentativa no mesmo processo aquecido.

O fullscreen pertence à janela WPF, não à Fullscreen API do DOM. A barra HTML ocupa uma faixa fora do HWND e desaparece após inatividade; Live oferece somente volume, mute, fullscreen e stop, enquanto VOD acrescenta play/pause, ±30 s, posição, duração e seek absoluto. Episódios acrescentam anterior/próximo e somente um EOF real do MPV inicia automaticamente o próximo episódio pelo mesmo resolver; com `--keep-open=yes`, tanto `end-file reason=eof` quanto a transição observada `eof-reached=false→true` convergem em um único encerramento natural por arquivo. Pause, seek, stop e a regra de conclusão do histórico nunca disparam autoplay. Trocas manuais e automáticas reutilizam o sidecar/handle e preservam exatamente o estado fullscreen anterior. O Host Engine salva a posição canônica aproximadamente a cada 30 segundos e também antes de pause, stop, seek, troca de conteúdo, Live, logout, troca de conta, fechamento e EOF natural; um generation/session fence impede gravações tardias no item seguinte. Após cada persistência válida, um evento sanitizado atualiza somente o episódio correspondente no detalhe aberto. Conteúdo com menos de 5 segundos não cria histórico e 90% ou os últimos 5 segundos marcam conclusão. Sem um runtime MPV válido e o host de vídeo nativo pronto, `iptv.local-view` não é anunciado.

O aplicativo procura primeiro `runtime/mpv/mpv.exe`. `SEMYRA_MPV_HOME` pode fornecer explicitamente o executável em Debug ou Release; a busca genérica pelo `PATH` permanece limitada a Debug. Separadamente, GStreamer continua sendo a engine exclusiva de publicação WHIP/LiveKit: `runtime/gstreamer/bin` ou `SEMYRA_GSTREAMER_HOME` explícito permanecem válidos em Debug e Release, enquanto a busca genérica pelo sistema continua limitada a Debug. A presença do MPV nunca habilita capacidades de publicação, e a presença do GStreamer nunca habilita reprodução visual local.

O MPV não é distribuído neste commit. O empacotamento e a conformidade de licenças do runtime serão definidos antes de qualquer distribuição binária do Desktop; esta implementação é independente e não incorpora código de players de terceiros.

## Fronteira futura

O Desktop possui a camada WebView/UI, uma ponte `postMessage` allowlisted na versão 1 e um Host Engine local in-process. Sem conta ativa ele anuncia somente `host.status` e `host.authorize`; as capabilities IPTV aparecem apenas depois de `account.activate`. Seu snapshot não inclui `desktop_profile_id`, identidade, dados da máquina nem credenciais.

Para a publicação de mídia, o backend emite uma Host Session curta de `media.publish`, ligada à sala, instance, revision e owner atuais. O validator fica somente como hash no banco; o token bruto chega ao Host Engine apenas em memória, é substituído por uma autorização nova e é apagado por `clear` ou `Stop()`. Segredos globais do bridge worker nunca são enviados ao cliente.

Ainda não existem cobrança, checkout, planos comerciais, favoritos VOD, tab de recentes VOD, EPG, gravação ou distribuição definitiva dos runtimes MPV/GStreamer. O booleano Premium atual serve somente como entitlement backend para publicação IPTV em sala. O Host Engine pode ser separado em outro processo no futuro se isolamento operacional se tornar necessário.
