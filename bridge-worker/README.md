# Semyra bridge worker

Artefato externo ao aplicativo HostGator que transporta uma fonte MPEG-TS privada para o WHIP criado pelo control plane. Ele requer PHP CLI com ext-curl, `proc_open`, Docker e a imagem fixada `livekit/gstreamer:1.22.8-prod-rs`.

## Modos

```bash
php bridge-worker/worker.php --check
php bridge-worker/worker.php --dry-run
php bridge-worker/worker.php --once
php bridge-worker/worker.php --loop
```

- `--check` valida configuração, daemon Docker, digest da imagem, GStreamer e plugins sem claim ou acesso ao provider.
- `--dry-run` preserva o protocolo de claim, heartbeat e report sem Docker, GStreamer ou mídia.
- `--once` faz no máximo um claim e supervisiona esse job até seu encerramento.
- `--loop` é o modo destinado ao futuro serviço Linux: busca e processa jobs sequencialmente, aguardando entre respostas sem job.

Um processo aceita no máximo um job de mídia ativo. O lock por worker ID impede duas instâncias locais equivalentes; não existe pool ou daemon manager nesta etapa.

## Shutdown do processo

Em Linux/Unix, `--loop` exige `pcntl` com suporte a `SIGTERM` e `SIGINT`; o worker falha fechado com configuração inválida se não puder instalar os handlers cooperativos. Quando disponível, o mesmo tratamento também protege `--once`. Windows sem `pcntl` continua suportando `--check`, `--dry-run` e `--once`; o modo `--once` mantém o comportamento local anterior sem signal handler.

O handler apenas registra o pedido de parada. O fluxo normal do runner interrompe imediatamente a mídia local e remove watchdog/runtime antes de tentar um report best-effort `failed` com `worker_shutdown`. Esse report não representa encerramento da transmissão: `desired_state` permanece `running`, a lease é liberada quando o report chega e o control plane pode reclamar uma nova geração após o reinício. Cada claim incrementa a geração monotônica `attempt_count`, mas `worker_shutdown` não consome o orçamento separado `failure_count`; falhas reais e lease expirada consomem esse orçamento. O control plane mantém um watermark de cleanup para não revisitar gerações já confirmadas ausentes. `lease_lost` continua impedindo reports por um owner antigo, e um `action=stop` autoritativo mantém a semântica de `stopped`.

A unit do systemd e o provisionamento do serviço serão tratados na etapa seguinte; este diretório ainda não contém arquivos de serviço ou instalação.

## Configuração local

Defina no ambiente as variáveis documentadas em `.env.example`. Em produção, `SEMYRA_CONTROL_URL` deve usar HTTPS; HTTP é aceito somente para `localhost` e `127.0.0.1` em `APP_ENV=local` ou `testing`.

`SEMYRA_SOURCE_CATALOG_PATH` aponta para um JSON privado, fora do Git:

```json
{
  "version": 1,
  "sources": {
    "channel:example": {
      "type": "http_mpegts",
      "url": "http://127.0.0.1:9000/example.ts"
    }
  }
}
```

Somente `http_mpegts` e URLs HTTP/HTTPS são aceitos. A resolução de `source_ref` é exata, sem concatenação, interpretação como path ou fallback. O arquivo real deve ficar em `bridge-worker/.private/` ou em outro diretório privado.

## Pipeline e segurança

O feeder cURL valida MPEG-TS na própria conexão e envia os bytes ao stdin do container. O pipeline usa H.264 passthrough (`h264parse`/`rtph264pay`) e converte AAC para Opus 48 kHz estéreo a 96 kbps. A imagem e o digest `sha256:0d9663ca1b0c13b752558b241494a7df61c6e1775ec37b455c76e3eb43b8e4f1` são verificados por `--check`.

A URL do provider nunca entra no container, control plane, banco ou browser. O endpoint WHIP e o lease token ficam apenas em memória, não são impressos ou persistidos, e o valor WHIP não aparece no argv do Docker. Stderr bruto de cURL, Docker e GStreamer não é encaminhado.

Heartbeats `keep` atualizam um watchdog sem segredos e um safety deadline anterior ao vencimento do lease. Um helper filho isola feeder e Docker do loop de controle; arquivos runtime contêm somente timestamp, lock ou marker `ready`. `action=stop`, `lease_lost`, falha do pipeline ou perda prolongada do control plane interrompem feeder e container. Não há retry local da mesma lease; o control plane decide novos attempts.
