# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**Pagou num Click** — multi-tenant PIX payment gateway/intermediary (Laravel 10). An external system calls a REST API to create a charge; the service creates the order with an upstream payment provider, returns a checkout link with QR code, polls the provider for status changes, and notifies the client system via webhook. Includes a Filament admin panel for account owners to track transactions.

Full technical documentation (flow diagrams, data model, API contract, scheduler internals, known issues) lives in [DOCUMENTACAO.md](DOCUMENTACAO.md) — read it before making non-trivial changes, it is kept up to date and is more detailed than this file.

- Stack: PHP ^8.1, Laravel 10, Filament 3.3 (panel), Livewire 3 (checkout UI), Sanctum, Telescope, Guzzle HTTP client, Vite + Tailwind 3.
- DB: MySQL via Laravel Sail. All IDs are UUIDs.
- Payment provider: v5 orders/charges REST API with Basic Auth (Pagar.me/Mundipagg-shaped). Base URL/credentials come from `.env` (`URL_INTEGRATION`, `USER_INTEGRATION`, `PASSWORD_INTEGRATION`).
- Locale: UI in Portuguese; `timezone = America/Sao_Paulo`, `locale = en`.

## Environment

Runs in Docker via **Laravel Sail** — always prefix artisan commands with `./vendor/bin/sail`, not bare `php`, once the containers exist.

### First-time setup (no `.env`, no `vendor/`, no containers yet)

There is no `.env.example` committed, and `vendor/bin/sail` doesn't exist until `composer install` has run — so the very first install can't use Sail yet. Bootstrap order:

```bash
# 1. composer install needs a PHP 8.1 environment matching the project; if the host PHP
#    is a different version, install via a disposable container instead of the host php/composer:
docker run --rm -v "$(pwd):/var/www/html" -w /var/www/html laravelsail/php81-composer:latest composer install

# 2. create .env by hand (see required vars below), then generate the app key the same way:
docker run --rm -v "$(pwd):/var/www/html" -w /var/www/html laravelsail/php81-composer:latest php artisan key:generate

# 3. frontend deps/build (host node is fine, no need for Sail/docker for this):
npm install && npm run build            # or `npm run dev` for Vite HMR

# 4. now vendor/bin/sail exists — bring up the containers:
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate

# 5. no seeder exists and registration is disabled in Filament — create the first
#    Account + User by hand via tinker:
./vendor/bin/sail artisan tinker --execute="
\$account = \App\Models\Account::create(['name' => 'Conta Demo']);
\App\Models\User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'), 'account_id' => \$account->id]);
"
```

Required project-specific env vars beyond Laravel defaults (see `docker-compose.yml` for the DB ones Sail expects — `DB_HOST=mysql`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`): `URL_INTEGRATION`, `USER_INTEGRATION`, `PASSWORD_INTEGRATION` (payment provider — without real credentials, `/api/create-payment` will fail at the HTTP call to the provider with a connection error, but everything before that, auth, validation, DB write, is exercised and works), `WEBHOOK_SECRET`, `APP_URL`.

**Port 80 conflict**: if the host already has something bound to port 80 (e.g. a system Apache/nginx), Sail's `laravel.test` container will silently lose that port — `docker ps` shows the mapping, but the host service answers first and you'll get a misleading 404 instead of Laravel's response. Check with `ss -tlnp | grep ':80 '`; if occupied, set `APP_PORT=8080` (or any free port) in `.env` and match it in `APP_URL`, then `./vendor/bin/sail up -d` again to recreate the container with the new mapping.

**Day-to-day once set up:**
```bash
./vendor/bin/sail up -d                 # start containers
./vendor/bin/sail down                  # stop (add -v only if you intend to wipe the DB volume)
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan tinker
./vendor/bin/sail artisan schedule:work # runs app:process-payment / app:process-webhook every 10s locally
```

Filament admin panel: `/admin/login`. The Filament `ConfigResource` create form will currently throw (see `Config::boot` bug in Known Issues below) — if you need a `Config`/`api_token` for a test account, create it via tinker instead of the panel until that's fixed.

## Tests

PHPUnit, configured in [phpunit.xml](phpunit.xml). Only the default Laravel example tests exist today — no coverage of the payment flow.

```bash
./vendor/bin/sail artisan test                                  # full suite
./vendor/bin/sail artisan test --filter=ExampleTest              # single test
./vendor/bin/sail artisan test tests/Feature/ExampleTest.php     # single file
./vendor/bin/sail composer run-script pint 2>/dev/null; ./vendor/bin/sail ./vendor/bin/pint  # Laravel Pint (code style)
```

## Architecture

### Request/background flow

```
Client system → POST /api/create-payment (Bearer token, middleware api.token)
             → CheckoutController creates order via ApiService (provider v5 orders) → Payment row (PENDING)
             → responds { payment_link: APP_URL/checkout/{payment.id} }

Payer opens /checkout/{id} → Livewire PaymentPage (polls every 5s)

Scheduler (every 10s, app/Console/Kernel.php):
  app:process-payment  → ProcessPaymentController: polls provider for each PENDING payment, updates status (or CANCELED if expired)
  app:process-webhook  → WebhookController: for payments with is_notified=false and status != PENDING, POSTs to notification_url with backoff [60s, 5m, 10m, 30m, 1h], gives up after 5 attempts
```

Console commands in `app/Console/Commands/` are thin — they only delegate to the controllers above, which hold the actual logic.

### Multi-tenancy via global scope

`Payment` and `Config` apply [app/Models/Scopes/UserScope.php](app/Models/Scopes/UserScope.php), filtering by `account_id = auth()->user()->account->id`. This has two consequences to keep in mind everywhere in this codebase:

- Any code path without an authenticated user (scheduler commands, public Livewire checkout) must call `withoutGlobalScopes()` to read these models.
- `account_id` is auto-filled on `creating` from the authenticated user (for `Payment`, only if not already set).
- `Account` itself has **no** scope — any authenticated user can access any account's admin records by UUID if they know the id (see DOCUMENTACAO.md §8 for the related known issues).

### API auth bridges a tokenless request into an authenticated one

`ApiTokenMiddleware` (alias `api.token`) takes the `Authorization: Bearer <api_token>` header, looks up the matching `Config` (without scope), takes the **first `User`** of that account, and does `auth()->loginUsingId(...)`. This synthetic login is what makes `UserScope`, `Payment::creating`, and the `CheckoutRequest` `unique` validation rule work for API requests — there is no per-request tenant resolution beyond this.

### Data model

```
accounts 1──N users
accounts 1──N configs   (practically 1:1)
accounts 1──N payments
```

`Config` holds per-account settings (`duration` of PIX validity, `notification_url`, `redirect_url`, `api_token`). `Payment` snapshots `duration`, `redirect_url`, and `notification_url` from `Config` at creation time, so later `Config` edits don't retroactively affect in-flight payments.

Status/method/type are backed by enums in `app/Enums/` (`StatusPaymentEnum`, `PaymentMethodEnum`, `TypeTransactionEnum` — the last is unused).

### Filament panel (`/admin`)

Registration is disabled (the `Register` page/class that creates `Account` + `User` is commented out), so the first account/user must be created via `tinker`. `AccountResource` and `ConfigResource` follow a "singleton" pattern — `/create` redirects to `/edit` if the record already exists. `PaymentResource` is read-only with a 0.75% processing-fee calculation duplicated across the infolist and two dashboard widgets (`PagamentosStats`, `PagamentosResumoStats`).

## Known issues to be aware of

DOCUMENTACAO.md §8 lists open issues found during a code review — notably: `PaymentTimer` Livewire component exposes a public `cancelar()` and public `expirationDate`, letting any payer cancel their own charge client-side (and a locally-canceled payment is no longer polled, so a PIX paid afterward is never recognized); `AccountResource` has no tenant scope; `api_token` is stored and displayed in plaintext; `Config::boot` has a variable-name bug (`$model->api_token` instead of `$item`) that makes the empty-token check always true. Check that section before "fixing" related behavior, since some of these may be intentional follow-up work rather than this-task scope.

`routes/web.php`'s `/checkout/{id}` throttle and the `web` middleware group's global throttle were removed (see DOCUMENTACAO.md §9) because they shared a rate-limit counter keyed by IP+domain with Livewire's polling requests, causing legitimate payers to get HTTP 429. `TrustProxies` is configured with Cloudflare's IP ranges specifically (not `*`) so `$request->ip()` resolves correctly behind Cloudflare without allowing IP spoofing via `X-Forwarded-For`.
