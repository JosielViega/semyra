# Instalador Windows Preview do Semyra

## Variante MediaLab (Etapa 15E)

A variante `Semyra MediaLab Preview` e separada da Preview basica. Ela usa AppId
proprio, instala em `%LOCALAPPDATA%\Programs\Semyra-MediaLab-Preview` e inclui os
runtimes app-relative MPV e GStreamer para homologacao no computador de
desenvolvimento. Nao modifica PATH, firewall, servicos, drivers ou variaveis globais.

Gerar o Release isolado e compilar o instalador com Inno Setup 7:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\desktop\scripts\Publish-WindowsMediaLabRelease.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\desktop\installer\scripts\Build-MediaLabInstaller.ps1
```

Saida privada esperada:

`storage/cache/releases/Semyra-0.15.0-preview.1-MediaLab-win-x64-setup.exe`

O manifesto `semyra-medialab-dependencies.json` registra hashes e classificacoes de
licenciamento. A proveniencia exata do MPV, GPL/x264, patentes H.264 e assinatura
digital continuam pendentes. Portanto, concluir os testes tecnicos nao autoriza
distribuicao publica.

> **INSTALADOR DE HOMOLOGAÇÃO INTERNA — NÃO DISTRIBUIR PUBLICAMENTE.**

Este projeto Inno Setup empacota somente o núcleo Windows x64 produzido pela Etapa
15B. MPV, GStreamer e x264 não são incluídos enquanto as decisões de licenciamento
da Etapa 15C estiverem pendentes.

## Identidade da edição Preview

- Produto exibido: `Semyra Desktop Preview`.
- Versão: `0.15.0-preview.1`.
- AppId lógico: `{02229D4E-6DD5-4215-A5E6-1EDE683A47D8}`.
- Arquitetura: x64-compatible.
- Instalação: por usuário, sem elevação.
- Diretório: `%LOCALAPPDATA%\Programs\Semyra-Preview`.

O AppId permanece estável em todas as próximas versões da edição Preview. Isso faz
com que reinstalações e upgrades atualizem a mesma instalação e o mesmo registro de
desinstalação. Uma futura edição pública deve receber outro AppId e outro diretório,
evitando que ela substitua acidentalmente a homologação.

## Pré-requisitos

1. Windows 10 build 17763 ou posterior, capaz de executar binários x64.
2. Release 15B em:
   `storage/cache/releases/semyra-desktop-0.15.0-preview.1-win-x64`.
3. Inno Setup 6.3 ou superior, instalado manualmente a partir do site oficial:
   <https://jrsoftware.org/isdl.php>.
4. Microsoft Edge WebView2 Evergreen Runtime.

O compilador Inno Setup não precisa estar no `PATH`. O script procura instalações
usuais ou aceita `-CompilerPath`. Nenhum script deste projeto baixa ou instala
programas automaticamente.

O instalador consulta a chave oficial do WebView2 Evergreen. Se o runtime estiver
ausente, apresenta uma orientação clara e permite concluir a instalação; o usuário
deve instalar o runtime oficial da Microsoft antes de abrir o Semyra. A distribuição
pública futura deverá decidir entre bootstrapper Evergreen verificado ou instalador
Evergreen standalone, incluindo verificação de assinatura e hash.

## Conteúdo permitido

O script não copia o diretório do release indiscriminadamente. A allowlist inclui:

- `*.exe`, `*.dll` e `*.json` do diretório raiz do release;
- assemblies `*.resources.dll` das pastas de idioma;
- `runtimes/win-x64/native/*.dll`.

No release 15B auditado isso representa 417 arquivos. São excluídos explicitamente:

- os três XML de documentação do SDK WebView2;
- `Semyra.MediaHost.pdb`.

O validador falha se surgir uma nova classe de arquivo sem classificação. Também
falha para MPV, GStreamer, x264, `.env`, bancos, playlists, logs, chaves, tokens ou
outros dados privados.

## Validar sem compilar

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\desktop\installer\scripts\Test-InstallerInputs.ps1
```

Para saída estruturada:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\desktop\installer\scripts\Test-InstallerInputs.ps1 -AsJson
```

## Compilar

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\desktop\installer\scripts\Build-PreviewInstaller.ps1
```

Com caminho explícito:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\desktop\installer\scripts\Build-PreviewInstaller.ps1 `
  -CompilerPath 'C:\Program Files (x86)\Inno Setup 6\ISCC.exe'
```

Saída esperada e ignorada pelo Git:

`storage/cache/releases/Semyra-0.15.0-preview.1-win-x64-setup.exe`

O build se recusa a sobrescrever um instalador existente. Remova manualmente um
artefato anterior somente depois de confirmar que ele não precisa ser preservado.

## Instalar e testar

Use somente a edição Preview e encerre voluntariamente o Semyra antes de atualizar.
O Inno Setup usa o Windows Restart Manager com `CloseApplications=yes`, sem modo
`force`, e não reinicia automaticamente os processos fechados.

Checklist de homologação:

1. Confirmar o SHA-256 do instalador informado pelo build.
2. Executar o instalador sem “Executar como administrador”.
3. Confirmar `%LOCALAPPDATA%\Programs\Semyra-Preview`.
4. Confirmar registro em Aplicativos Instalados somente para o usuário atual.
5. Confirmar atalho `Semyra Preview` no Menu Iniciar.
6. Repetir com a opção de atalho na Área de Trabalho.
7. Abrir o Desktop e confirmar `https://semyra.jzlcode.com.br/`.
8. Validar login, navegação, salas, interface e chat de texto.
9. Confirmar que IPTV local/publicação IPTV/Screen informam indisponibilidade sem
   MPV/GStreamer, sem simular capabilities e sem travar.
10. Encerrar o Desktop antes de continuar.

Não acessar credenciais IPTV, não executar transmissões e não modificar bancos.

## Testar atualização

1. Com a Preview instalada e o Desktop encerrado, executar o mesmo instalador.
2. Confirmar que o diretório e o AppId existentes são reutilizados.
3. Confirmar um único registro em Aplicativos Instalados.
4. Confirmar que atalhos não foram duplicados.
5. Abrir o Desktop novamente e repetir o fluxo web básico.
6. Confirmar que `%LOCALAPPDATA%\Semyra` permaneceu intacto.

Não executar atualização durante publicação ativa. Não há atualização automática.

## Desinstalar

Use **Configurações > Aplicativos > Semyra Desktop Preview > Desinstalar**.

A desinstalação remove somente `%LOCALAPPDATA%\Programs\Semyra-Preview`, atalhos e
o registro de desinstalação. O script não possui `[UninstallDelete]` e nunca remove:

`%LOCALAPPDATA%\Semyra`

Essa pasta preserva SQLite, fontes IPTV, favoritos, progresso, credenciais protegidas
por DPAPI, contas e o perfil WebView2.

## Limitações desta Preview

- Não inclui MPV, GStreamer nem x264.
- Não representa a experiência completa de mídia.
- Não é assinada digitalmente nesta etapa.
- Pode gerar aviso do SmartScreen; não contornar nem desativar a proteção.
- Não deve ser publicada em GitHub Releases nem disponibilizada no site.

## Evolução para distribuição pública

Antes do instalador público:

1. Resolver as pendências jurídicas/técnicas registradas na Etapa 15C.
2. Fixar origem, versão, hashes, fontes e licenças de MPV/GStreamer/x264.
3. Criar `ThirdPartyNotices` e SBOM reproduzíveis.
4. Validar o runtime app-relative em VM limpa.
5. Definir a estratégia oficial do WebView2.
6. Assinar instalador e executáveis próprios com certificado legítimo.
7. Usar novo AppId para a edição pública.
8. Executar gates completos de instalação, upgrade, desinstalação e mídia.
