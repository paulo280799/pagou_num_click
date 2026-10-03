# Changelog

## 2026-10-03
- Adicionado `CLAUDE.md` com guia de arquitetura e comandos para o Claude Code (baseado em `DOCUMENTACAO.md`, `composer.json`, `package.json`, `phpunit.xml`, `Kernel.php`).
- Projeto rodado localmente do zero via Docker/Sail: `.env` criado manualmente (sem `.env.example` no repo), `composer install`/`key:generate` via container descartável `laravelsail/php81-composer`, `npm install && npm run build`, containers (`mysql` + `laravel.test`) subidos e migrados, primeiro `Account`/`User` criado via tinker. Documentado em `CLAUDE.md` § Environment.
- Descoberto conflito de porta 80 com Apache do host (mascarava respostas da app com 404 do Apache) — contornado com `APP_PORT=8080` no `.env`. Documentado como "gotcha" em `CLAUDE.md`.
- Confirmado bug já catalogado em `DOCUMENTACAO.md` §8 item 5 (`Config::boot` usa `$model` inexistente): `Config` precisa ser criado via tinker, não pelo formulário do Filament, até ser corrigido.
