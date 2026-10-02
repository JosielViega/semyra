# Semyra bridge worker

Componente externo ao aplicativo HostGator. Na Etapa 10B.3A ele implementa somente o protocolo pull em modo `--dry-run`: claim, heartbeat e report, sem GStreamer, provider ou mídia.

Configure as variáveis do `.env.example` no ambiente do processo e execute:

```bash
php bridge-worker/worker.php --dry-run
```

O endpoint WHIP recebido permanece somente em memória e nunca é exibido ou salvo. Em produção, `SEMYRA_CONTROL_URL` deve usar HTTPS; HTTP é aceito apenas para `localhost` e `127.0.0.1` quando `APP_ENV` é `local` ou `testing`.
