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

Room names e participant identities são opacos, sem código público de sala, e-mail, nome, user ID ou participant key em claro. O acesso exige CSRF, participação vigente na sala, identidade autenticada compatível e uma transmissão IPTV Live na mesma revisão solicitada. Cada emissão recebe viewer identity aleatória; a identidade esperada do publisher deriva de room, revision e timestamp imutável de início, mudando também quando uma nova linha reinicia a revision após o encerramento anterior.

Tokens permanecem somente em memória durante a requisição e a conexão; não entram em DOM, URL, cookie, logs, `localStorage`, `sessionStorage` ou IndexedDB. O browser conecta com `autoSubscribe=false` e aceita para playback somente tracks cuja participant identity corresponda exatamente ao publisher esperado retornado pelo backend. Esse binding funcional reduz exposição acidental no player, mas não substitui autorização server-side.

Credenciais do provider IPTV, origem MPEG-TS e endpoints/chaves WHIP continuam fora do browser e da aplicação produtiva nesta etapa. O API key aparece como issuer dentro do JWT pelo contrato LiveKit, mas não é retornado como campo explícito.

## Mirror de produção

O builder HostGator usa allowlist e falha se detectar configurações do servidor, secrets, certificados ou diretórios de dados no mirror. `.env`, `.htaccess`, uploads, logs e cache permanecem próprios de cada instalação. O builder não transmite arquivos e não executa migrations.
