# Changelog

## 2026-10-04 (3)
- Adicionada página de vendas pública em `GET /` (`routes/web.php`, rota `landing`; view `resources/views/landing.blade.php`), implementada a partir da prévia aprovada pelo cliente. CSS/JS inline na view (sem build Vite), fontes via Google Fonts, tema claro/escuro automático por `prefers-color-scheme`. Seções: hero com checkout de exemplo, como funciona, recursos, integração (exemplo de `POST /api/create-payment`), planos, FAQ e CTA.
- Destino dos botões "Quero contratar"/"Falar com a gente" configurável via `LANDING_CONTACT_URL` (`config/services.php` → `services.landing.contact_url`); sem a variável, cai para a âncora `#topo`. **Pendente:** definir o contato real (WhatsApp/e-mail/formulário) e setar no `.env`.
- Preços dos planos e taxas ficaram como "Sob consulta" (a prévia usava "A DEFINIR", que não deve ir a público). Nomes dos planos (Início/Negócio/Sob medida) são sugestão e podem mudar. O exemplo de payload da API na página é ilustrativo — conferir contra `CheckoutRequest`.
- Adicionado `tests/Feature/LandingPageTest.php` (render, CTA com e sem `LANDING_CONTACT_URL`). Suíte completa: 83 testes passando.

## 2026-10-04 (2)
- Corrigido `Config::boot()` (`app/Models/Config.php`): usava `$model->api_token` (variável inexistente) em vez de `$item`. `empty()` nunca lançava erro em variável indefinida, só sempre avaliava `true`, sobrescrevendo qualquer `api_token` fornecido. Agora o guard funciona de verdade.
- Corrigido `CheckoutController::store` (`app/Http/Controllers/CheckoutController.php`): `payment_method`/`status` desconhecidos na resposta do provedor (`tryFrom` retornando `null`) quebravam com `ErrorException` **depois** do pedido já criado no provedor. Agora cai para `PaymentMethodEnum::PIX`/`StatusPaymentEnum::PENDING` e loga `warning` com o `id` do pedido pra investigação manual — decisão: time preferiu não deixar o pagamento órfão (sem `Payment` local) nesse cenário raro.
- Testes atualizados (`tests/Feature/Models/ConfigTest.php`, `tests/Feature/Api/CreatePaymentTest.php`) para cobrir o comportamento corrigido em vez do bug.

## 2026-10-04
- Adicionada suíte de testes (PHPUnit) cobrindo 81,7% das linhas de `app/`: factories (`Account`, `Config`, `Payment`), fluxo completo de `POST /api/create-payment` (sucesso, erro do provedor, validação, duplicidade de `refExternal`), `app:process-payment`/`app:process-webhook` (incl. backoff e limite de tentativas), `ApiTokenMiddleware`, componentes Livewire do checkout, e smoke tests do painel Filament (login, singleton create→edit de `AccountResource`/`ConfigResource`, widgets de stats). Banco de testes usa a base `testing` já provisionada pelo Sail (`docker/mysql/create-testing-database.sh`), configurada em `phpunit.xml`.
- `database/factories/ConfigFactory` cria registros via `Config::withoutEvents` porque `Config::boot()` sobrescreve `account_id` a partir do usuário autenticado em toda criação (sem guarda, diferente de `Payment`) — necessário para fixtures de teste sem usuário logado.
- Bugs confirmados por teste, não corrigidos (fora do escopo desta tarefa, ver `DOCUMENTACAO.md` §8): item 5 (`Config::boot` usa `$model` inexistente — token sempre regenerado, nunca falha silenciosamente por `empty()` não lançar erro em variável indefinida) e item 9 (`CheckoutController` lança `ErrorException` ao receber status desconhecido do provedor, após o pedido já ter sido criado upstream).
- Removido `tests/Feature/ExampleTest.php`: testava `GET /` mas a rota não existe em `routes/web.php` (só `/checkout/{id}`).

## 2026-10-03
- Adicionado `CLAUDE.md` com guia de arquitetura e comandos para o Claude Code (baseado em `DOCUMENTACAO.md`, `composer.json`, `package.json`, `phpunit.xml`, `Kernel.php`).
- Projeto rodado localmente do zero via Docker/Sail: `.env` criado manualmente (sem `.env.example` no repo), `composer install`/`key:generate` via container descartável `laravelsail/php81-composer`, `npm install && npm run build`, containers (`mysql` + `laravel.test`) subidos e migrados, primeiro `Account`/`User` criado via tinker. Documentado em `CLAUDE.md` § Environment.
- Descoberto conflito de porta 80 com Apache do host (mascarava respostas da app com 404 do Apache) — contornado com `APP_PORT=8080` no `.env`. Documentado como "gotcha" em `CLAUDE.md`.
- Confirmado bug já catalogado em `DOCUMENTACAO.md` §8 item 5 (`Config::boot` usa `$model` inexistente): `Config` precisa ser criado via tinker, não pelo formulário do Filament, até ser corrigido.
