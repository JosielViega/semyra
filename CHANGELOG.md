# Changelog

Todas as mudanças relevantes deste projeto serão documentadas aqui.

## [Unreleased]

### Added

- Guia permanente `docs/PLANO_DE_ESTUDO_E_REVISAO.md` para estudo ativo, revisão por fluxo e evolução segura do modelo.
- Atalho no README para iniciar o estudo estruturado do projeto.

## [0.3.0] - 2026-09-08

### Added

- registro persistente e concorrente de portas em `~/.modeloPHP/ports.json`
- reservas por caminho absoluto normalizado do projeto
- comandos `composer port:status` e `composer port:release`
- limpeza conservadora de reservas claramente antigas
- testes de colisões entre projetos, corrupção e idempotência

## [0.2.0] - 2026-09-08

### Added

- `composer setup` com preservação de `.env` e seleção automática de porta livre
- builder portátil `composer deploy:hostgator`
- manifesto allowlist e validações contra secrets/configurações do servidor
- `vendor` exclusivo de produção no mirror gerado
- documentação de primeira instalação e atualizações HostGator/cPanel
- validação do mirror na CI

## [0.1.0] - 2026-09-08

### Added

- Composer obrigatório e autoload PSR-4
- bootstrap e configuração por ambiente
- Router, Request, Response, Controllers, Views e layout
- PDO e estrutura para Repositories e Services
- Validation, CSRF, Sessions, flash messages e escape HTML
- Error Handler e logs
- migrations SQL e diretório de seeds
- testes, lint e comando de validação completa
- CI do GitHub
- documentação de arquitetura, segurança e desenvolvimento local
- porta local independente e configurável
