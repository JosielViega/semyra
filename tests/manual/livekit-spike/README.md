# Laboratório local LiveKit Cloud + voz/IPTV

## Objetivo

Este laboratório experimental valida voz WebRTC (Etapa 10A.4A), o bridge IPTV MPEG-TS → WHIP → LiveKit (Etapa 10A.4B) e prepara o smoke test local do viewer real das salas Semyra (Etapa 10B.2). O harness não cria fluxo produtivo de seleção IPTV nem lifecycle de bridge.

## Viewer real Semyra (10B.2)

Use somente com `APP_ENV=local` ou `testing` e banco local. O harness recusa outros ambientes:

```powershell
php tests/manual/livekit-spike/semyra-room-fixture.php start ROOMCODE
php tests/manual/livekit-spike/create-semyra-whip-ingress.php
powershell -ExecutionPolicy Bypass -File tests/manual/livekit-spike/run-semyra-local.ps1
```

Publique primeiro o pipeline sintético no endpoint WHIP privado já preparado e abra a mesma sala em dois browsers. A fixture grava room name e publisher identity somente em `.private`; o servidor recebe credenciais LiveKit apenas no environment do processo. Para troca controlada, execute novamente `start ROOMCODE`, recrie o Ingress e confirme o novo `instance_id`. Para cleanup:

```powershell
php tests/manual/livekit-spike/delete-whip-ingress.php
php tests/manual/livekit-spike/semyra-room-fixture.php end
```

O `DELETE` da fixture exige sala, revisão, timestamp, fonte e owner sintético originais, portanto não remove uma transmissão posterior. Feche os viewers, pare feeder/GStreamer e o servidor local. Nunca execute esse fluxo contra HostGator ou produção.

## Arquitetura

- `token.php`: endpoint PHP `POST application/json` que lê credenciais apenas de `.private/livekit.env`, escolhe uma sala fixa e emite um token de participante por dez minutos.
- `lib.php`: lógica testável do endpoint, identidade opaca aleatória e grants mínimos.
- `router.php`: router local com allowlist; impede servir `.private`, o `vendor/` PHP e arquivos não previstos.
- `livekit-spike.js`: cliente browser em JavaScript puro, usando o SDK oficial empacotado localmente.
- `livekit-diagnostics.js`: extrator por lista branca de estatísticas WebRTC; normaliza unidades e nunca retorna o relatório RTC bruto.
- `vendor/`: dependências do Composer exclusivas do laboratório.
- `vendor-js/livekit-client.umd.js`: bundle UMD oficial do cliente browser.

O API secret permanece somente no arquivo privado e na memória do PHP. O browser recebe apenas `server_url` e um participant token temporário. O token fica somente em memória: não há query string, cookies, `localStorage`, `sessionStorage` ou IndexedDB.

## Dependências

- PHP community SDK: `agence104/livekit-server-sdk` 1.3.5, obtido pelo Composer a partir de <https://github.com/agence104/livekit-server-sdk-php>. O metadata do pacote declara MIT; o arquivo `LICENSE` distribuído contém Apache License 2.0.
- Browser SDK oficial: `livekit-client` 2.22.3, obtido com `npm pack` a partir do pacote oficial <https://www.npmjs.com/package/livekit-client>.
- Bundle: `vendor-js/livekit-client.umd.js`.
- SHA-256 do bundle: `7FA17E37AF5E996D8A25F15A637DCC0620215BC01B394E5D209F726AFE7DC04D`.
- Licença distribuída do cliente: Apache-2.0 em `vendor-js/LICENSE-livekit-client.txt`.

Não existe projeto Node permanente, CDN, framework frontend ou dependência adicionada ao Composer raiz do Semyra. `vendor/` e `vendor-js/` são artefatos locais gerados e ignorados pelo Git.

Prepare as dependências a partir da raiz do repositório:

```powershell
composer install --working-dir tests/manual/livekit-spike
powershell -ExecutionPolicy Bypass -File tests/manual/prepare-browser-dependencies.ps1
```

O Composer usa o lock file isolado, atualmente com o SDK 1.3.5. O script PowerShell baixa o bundle browser na versão fixa, valida seu SHA-256 e não deixa projeto Node permanente.

## Configuração local

Preencha localmente `tests/manual/livekit-spike/.private/livekit.env`:

```dotenv
LIVEKIT_URL=
LIVEKIT_API_KEY=
LIVEKIT_API_SECRET=
```

Não compartilhe esse arquivo, seus valores ou participant tokens. `.private/` é protegido apenas pelo `.git/info/exclude` local.

## Iniciar

Na pasta `tests/manual/livekit-spike`:

```powershell
php -S 127.0.0.1:8017 router.php
```

Abra `http://127.0.0.1:8017/` em duas abas ou browsers. Use fones de ouvido para evitar feedback acústico.

1. Conecte A e B.
2. Em A, ative o microfone por ação explícita; confirme tecnicamente a assinatura em B e, humanamente, o áudio.
3. Em B, ative o áudio remoto se o navegador bloquear autoplay e depois ative o microfone.
4. Teste mute/unmute nos dois sentidos.
5. Desconecte B e confirme os eventos em A.
6. Use **Reconectar** para uma nova sessão curta.
7. Desconecte as duas abas ao terminar e registre aproximadamente os participant-minutes exibidos.

O laboratório nunca solicita câmera. Eventos e métricas mostram apenas estado, contagens, tipo de track e identidades abreviadas; tokens e credenciais não são registrados.

## Verificações offline

```powershell
php tests.php
php leak-check.php
node livekit-diagnostics.test.cjs
node --check livekit-diagnostics.js
node --check livekit-spike.js
```

Os testes validam método e JSON, configuração ausente, headers `no-store`, URL vinda da configuração, resposta mínima, TTL, grants, identidade opaca e sala fixa. Também validam normalização de RTT/jitter, perda percentual, jitter buffer médio, qualidade e transporte sanitizado. JWTs de teste são criados com valores aleatórios apenas em memória e nunca são impressos.

## Riscos e limites

- O endpoint é somente local. Uma integração real exigirá sessão Semyra, autorização de sala, CSRF/origin policy e rate limiting.
- Confirmação técnica de track não comprova que uma pessoa ouviu áudio; o teste humano continua obrigatório.
- Autoplay pode exigir clique em **Ativar áudio**.
- Credenciais do LiveKit Cloud dão acesso faturável e devem permanecer privadas.
- O teste deve ser curto e ambas as abas devem desconectar ao final.
- O painel **WebRTC diagnostics** coleta apenas três amostras (aproximadamente 10, 30 e 60 segundos) e mostra somente campos permitidos. `RTCStatsReport` permanece transitório em memória; IP, hostname, candidate address/foundation/URL e SDP não são exibidos nem persistidos.

## Resultado local observado

O teste técnico com credenciais locais foi concluído em duas abas do Chrome:

- dois participantes com identidades opacas conectaram à sala fixa e descobriram um ao outro;
- A e B publicaram uma track de microfone e assinaram uma track de áudio remota;
- mute/unmute gerou `track-muted` e `track-unmuted` corretamente nos dois sentidos;
- a saída de B removeu participante e track remota em A;
- B conectou novamente com uma nova identidade opaca;
- o autoplay não foi bloqueado nessa execução;
- A e B terminaram em estado desconectado;
- a interface registrou 13,10 e 12,69 participant-minutes nas sessões principais; incluindo sessões preliminares curtas, o consumo aproximado do spike foi 28 participant-minutes;
- áudio A → B e B → A foi confirmado por uma pessoa; mute/unmute humano funcionou, a qualidade foi classificada conservadoramente como aceitável e a latência percebida como baixa, sem problema audível óbvio;
- as amostras WebRTC de 10/30/60 s mostraram RTT de aproximadamente 17–22 ms, jitter de envio de 0,3–1,5 ms, jitter recebido de 2–3 ms, zero packet loss e connection quality `excellent`;
- a validação de voz 10A.4A está técnica e funcionalmente aprovada.

Nenhum valor de configuração, participant token ou identidade completa foi persistido neste resultado.

## IPTV via WHIP

O fluxo validado usa a imagem `livekit/gstreamer:1.22.8-prod-rs` (digest observado `sha256:0d9663ca1b0c13b752558b241494a7df61c6e1775ec37b455c76e3eb43b8e4f1`). O feeder recebe MPEG-TS H.264/AAC autorizado, preserva H.264, converte AAC para Opus e publica por WHIP; o LiveKit distribui H.264/Opus aos viewers sem transcoding no serviço.

Os comandos e a sequência completa estão em `IPTV_WHIP_REPORT.md`. Em resumo:

1. crie um HTTP ingress com `create-whip-ingress.php`;
2. execute primeiro `run-synthetic-whip.sh` para validar o pipeline controlado;
3. para uma fonte privada autorizada, use `feed-private-iptv.php` para entregar a URL somente por stdin ao container;
4. abra `iptv-viewer.html` em dois browsers e gere tokens efêmeros com `iptv-viewer-token.php`;
5. encerre feeder, viewers e ingress com os scripts de cleanup;
6. execute `audit-whip-cleanup.php` e o leak check consolidado.

No teste real, dois browsers exibiram e reproduziram o mesmo canal em 1920×1080/30 fps, com sincronização aparente considerada boa. A sessão controlada permaneceu por mais de 60 segundos com um ingress e dois viewers. Isso comprova a viabilidade técnica desse caminho para a fonte testada; não comprova suporte universal de codecs/providers, tolerância de produção, recuperação automática, autorização Semyra nem custo em escala.

## Cleanup obrigatório

O encerramento deve deixar zero ingress ativos, containers/feeders, viewers conectados, servidor na porta local e arquivos efêmeros de WHIP. Não use `down -v` nem remova dados fora deste laboratório. O cache local da imagem Docker, `vendor/`, `vendor-js/`, `.private/livekit.env` e outros dados privados continuam locais e não entram no Git.
