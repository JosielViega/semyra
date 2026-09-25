# Changelog

Todas as mudanças relevantes do Semyra serão documentadas aqui.

## [Unreleased]

### Added

- Início do projeto Semyra com base na infraestrutura técnica do `modeloPHP`.
- Identidade inicial da aplicação e conceito “Assista junto.”.
- Página inicial e identidade visual base da aplicação.
- Documentação inicial do projeto e do seu estado atual.
- Criação e persistência de salas a partir de URLs suportadas do YouTube.
- Parser local de URLs do YouTube e extração do video ID.
- Geração segura de códigos públicos de sala com tratamento limitado de colisões.
- Página inicial funcional para criar salas e página básica para consultar uma sala.
- Reprodução de vídeo ou Live dentro da sala usando a YouTube IFrame Player API.
- Player responsivo com controles nativos e tratamento básico de erros de incorporação.
- Compartilhamento de salas pela própria URL pública.
- Cópia do link com Clipboard API e fallback por seleção manual.
- Compartilhamento nativo progressivo através da Web Share API.
- Entrada anônima por apelido em cada sala e sessão do navegador.
- Presença de participantes por polling, com atualização a cada 10 segundos e expiração após 45 segundos.
- Persistência segura de participantes usando apenas o hash SHA-256 da chave anônima.
- Telemetria observacional de estado, posição e duração do YouTube Player.
- Medição aproximada de drift entre participantes em reprodução, sem qualquer correção automática.

### Removed

- Página, formulário, rota e processamento demonstrativos herdados da base técnica.
