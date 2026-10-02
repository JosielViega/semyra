# Segurança

Estas regras são requisitos do projeto, não sugestões:

1. Nunca versionar secrets, credenciais, certificados privados ou dados reais.
2. Usar `.env` local e manter apenas `.env.example` no Git.
3. Nunca armazenar senha em texto puro.
4. Gerar hashes de senha com `password_hash()`.
5. Conferir senhas com `password_verify()`.
6. Usar prepared statements PDO para valores.
7. Nunca concatenar entrada em SQL; tabela, coluna e `ORDER BY` dinâmicos exigem whitelist.
8. Escapar saída HTML dinâmica com `e()` por padrão.
9. Exigir CSRF em toda ação que muda estado.
10. Validar autenticação e autorização no backend para cada recurso.
11. Validar upload por erro, limite de tamanho, MIME real via `finfo`, extensão permitida, nome aleatório e destino.
12. Nunca confiar apenas em validação JavaScript.
13. Não expor exceptions, stack traces, SQL ou caminhos internos em produção.
14. Usar cookies HttpOnly, SameSite e Secure sob HTTPS.
15. Não usar GET para criar, alterar ou excluir dados.
16. Instalar dependências PHP somente via Composer e manter um único `vendor/`.
17. Atualizar dependências conscientemente, revisar changelogs e versionar `composer.lock`.
18. Não expor `.env`; o Document Root deve ser `public/`.
19. Não versionar logs e evitar dados pessoais ou secrets neles.
20. Não versionar uploads de usuários e impedir execução de scripts no diretório.

## Sessão e autenticação futura

Regenerar o ID da sessão após login e mudança de privilégio. Não guardar senha, segredo externo ou token reutilizável em cookie. Um futuro “remember me” deve usar token aleatório, armazenado de forma segura, com expiração e revogação.

## Produção

Configure `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE=true`, HTTPS e permissões mínimas. O usuário recebe erro genérico com referência; detalhes ficam em `storage/logs`. Proteja também logs e backups no servidor.

## Uploads futuros

A infraestrutura inicial apenas prepara `public/uploads` e bloqueia extensões PHP via Apache. Antes de aceitar arquivos, imponha tamanho máximo, use `finfo` no conteúdo, mapeie MIME a extensões permitidas, gere nomes com `random_bytes`, impeça sobrescrita e prefira armazenamento fora do Document Root quando downloads puderem passar por autorização.

## Processos periódicos

Tarefas críticas não devem rodar durante uma visita HTTP. Use cron chamando script CLI dedicado quando esse requisito surgir.

## LiveKit

O LiveKit é uma integração opcional e fica desabilitado por padrão. `LIVEKIT_API_SECRET` existe somente no backend; nunca deve ser enviado ao browser, persistido no banco ou registrado em logs. O endpoint de viewer emite JWT curto, com dez minutos de validade, `roomJoin` e `canSubscribe`, negando publicação de mídia e dados. As respostas usam `no-store`, `no-cache` e `nosniff`.

Room names e participant identities são opacos, sem código público de sala, e-mail, nome, user ID ou participant key em claro. O acesso exige CSRF, participação vigente na sala, identidade autenticada compatível e uma transmissão IPTV Live com o mesmo `instance_id` e revisão solicitados. Cada emissão recebe viewer identity aleatória; a identidade esperada do publisher deriva de namespace, room ID e `instance_id`, mudando em todo start ou replacement mesmo quando a revision reinicia no mesmo valor.

O `instance_id` é um valor público opaco, imutável por execução e não funciona como credencial. Toda mutation de autoridade sobre a transmissão — end, playback e observação de live edge — continua exigindo owner válido e aplica o `instance_id` na mesma operação SQL de compare-and-swap, junto às revisions pertinentes. Assim, requests antigas não alteram uma nova transmissão do mesmo owner.

Tokens permanecem somente em memória durante a requisição e a conexão; não entram em DOM, URL, cookie, logs, `localStorage`, `sessionStorage` ou IndexedDB. O browser conecta com `autoSubscribe=false` e aceita para playback somente tracks cuja participant identity corresponda exatamente ao publisher esperado retornado pelo backend. Esse binding funcional reduz exposição acidental no player, mas não substitui autorização server-side.

O worker do bridge autentica-se por um machine secret independente de 64 caracteres hexadecimais no header `X-Semyra-Worker-Token`. Esse segredo não é uma API key LiveKit, lease, credencial WHIP ou credencial de provider. A API interna não usa sessão nem CSRF porque é machine-to-machine; em produção exige HTTPS, enquanto HTTP é aceito somente em `localhost`/`127.0.0.1` para `local` ou `testing`.

Cada claim emite um lease token aleatório de 256 bits. O valor bruto fica somente na memória do worker e o MySQL armazena apenas seu SHA-256. Worker ID, job ID, `transmission_instance_id` e lease vigente formam o fence das operações seguintes. `instance_id` separa transmissões; `attempt_count` separa as gerações de worker/Ingress dentro da mesma transmissão; o lease token autoriza a geração atual. O nome externo `smy_b_<instance_id>_a<attempt_count>` impede que um cleanup já iniciado pela lease expirada descubra o Ingress de um attempt posterior. Publisher e room permanecem instance-bound e não são usados isoladamente para delete. `instance_id` e attempt são públicos e opacos, mas não autenticam sem machine secret e lease.

`LIVEKIT_API_SECRET` e a API key permanecem exclusivamente no control plane. O Semyra entrega ao worker somente a conexão WHIP efêmera da instância e nunca a persiste, registra ou inclui em documentação. Somente `ingress_id` pode permanecer no banco para cleanup. O retorno potencialmente sensível de `ListIngress` é filtrado dentro do gateway: a aplicação recebe apenas IDs de recursos com match exato de room, nome, publisher e input WHIP; URL, stream key e o objeto `IngressInfo` não atravessam essa abstração. Um erro Twirp estruturado `not_found` no delete é tratado como recurso já ausente, sem parsing da mensagem.

Efeitos externos permanecem recuperáveis pelo ownership determinístico e pela reconciliação via `ListIngress`, inclusive após crash antes de persistir o ID ou exclusão da sala. Para isso, `room_id` é mantido como snapshot operacional no job, sem FK para `rooms`; ele não concede acesso nem substitui validação de lease. Credenciais e URLs do provider IPTV permanecem worker-side; o Semyra guarda apenas `source_ref` opaco e não secreto. O API key pode aparecer como issuer em JWTs de viewer pelo contrato LiveKit, mas não é retornado como campo explícito da API do bridge.

## Mirror de produção

O builder HostGator usa allowlist e falha se detectar configurações do servidor, secrets, certificados ou diretórios de dados no mirror. `.env`, `.htaccess`, uploads, logs e cache permanecem próprios de cada instalação. O builder não transmite arquivos e não executa migrations.
