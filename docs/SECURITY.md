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

## Mirror de produção

O builder HostGator usa allowlist e falha se detectar configurações do servidor, secrets, certificados ou diretórios de dados no mirror. `.env`, `.htaccess`, uploads, logs e cache permanecem próprios de cada instalação. O builder não transmite arquivos e não executa migrations.
